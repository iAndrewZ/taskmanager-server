<?php

namespace App\Http\Controllers;

use App\Models\ArchivedTasks;
use App\Models\BoardMembers;
use App\Models\Status;
use App\Models\Task;
use App\Models\TaskAssignedTo;
use App\Models\TaskHistory;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class TaskController extends ApiController
{
    public function add(Request $request)
    {
        try {
            $validate = Validator::make($request->all(), [
                'name' => 'required|max:50',
                'status_id' => 'required|exists:statuses,id',
            ]);

            if ($validate->fails()) {
                return $this->sendError("Bad request", $validate->messages()->toArray());
            }

            $authUser = Auth::user();
            $status = Status::find($request->get("status_id"));
            $foundUser = BoardMembers::where("board_id", $status->board->id)->where("user_id", $authUser->id)->first();

            if (!$foundUser || $foundUser->role !== "Admin") {
                return $this->sendError("Not allowed to perform this action", [], Response::HTTP_METHOD_NOT_ALLOWED);
            }

            $task = new Task();
            $task->name = $request->get("name");
            $task->status_id = $request->get("status_id");
            $task->save();

            $taskHistory = new TaskHistory();
            $taskHistory->task_id = $task->id;
            $taskHistory->user_id = $authUser->id;
            $taskHistory->action = "$authUser->email" . " created the task";
            $taskHistory->save();

            return $this->sendResponse($task->toArray(), Response::HTTP_CREATED);
        } catch (Exception $exception) {
            Log::error($exception);
            return $this->sendError('Something went wrong, please contact administrator!', [], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function getAllTasksForStatus($statusId)
    {
        try {
            $status = Status::find($statusId);
            $tasks = $status->tasks;

            if (!$tasks) {
                return $this->sendError('tasks not found!', [], Response::HTTP_NOT_FOUND);
            }

            $authUser = Auth::user();
            $foundUser = BoardMembers::where("board_id", $status->board->id)->where("user_id", $authUser->id)->first();

            if (!$foundUser) {
                return $this->sendError("Not allowed to perform this action", [], Response::HTTP_METHOD_NOT_ALLOWED);
            }

            return $this->sendResponse($tasks->toArray());
        } catch (Exception $exception) {
            Log::error($exception);

            return $this->sendError('Something went wrong, please contact administrator!', [], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function update($id, Request $request)
    {
        try {
            $task = Task::find($id);
            $authUser = Auth::user();
            $foundUser = BoardMembers::where("board_id", $task->status->board->id)->where("user_id", $authUser->id)->first();

            if (!$foundUser || $foundUser->role !== "Admin") {
                return $this->sendError("Not allowed to perform this action", [], Response::HTTP_METHOD_NOT_ALLOWED);
            }

            if (!$task) {
                return $this->sendError('task not found!', [], Response::HTTP_NOT_FOUND);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'required|max:50',
            ]);

            if ($validator->fails()) {
                return $this->sendError('Bad request!', $validator->messages()->toArray());
            }

            $prevTaskName = $task->name;
            $task->name = $request->get('name');
            $task->save();

            $taskHistory = new TaskHistory();
            $taskHistory->task_id = $task->id;
            $taskHistory->user_id = $authUser->id;
            $taskHistory->action = "$authUser->email" . " changed task name from {$prevTaskName} to {$task->name}";
            $taskHistory->save();

            return $this->sendResponse($task->toArray());
        } catch (Exception $exception) {
            Log::error($exception);
            return $this->sendError('Something went wrong, please contact administrator!', [], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function archive($id)
    {
        try {
            $user = Auth::user();
            $task = Task::find($id);

            if ($user->id != $task->status->board->owner_id) {
                return $this->sendError("Not allowed to perform this action", [], Response::HTTP_METHOD_NOT_ALLOWED);
            }

            if (!$task) {
                return $this->sendError('task not found!', [], Response::HTTP_NOT_FOUND);
            }

            if (!$task->isArchived) {
                $archiveTask = new ArchivedTasks();
                $archiveTask->task_id = $task->id;
                $archiveTask->archived_by = $user->id;
                $archiveTask->save();

                $taskHistory = new TaskHistory();
                $taskHistory->task_id = $task->id;
                $taskHistory->user_id = $user->id;
                $taskHistory->action = "$user->email" . " archived the task";
                $taskHistory->save();
            } else {
                $archiveTask = ArchivedTasks::where("task_id", $task->id)->first();
                $archiveTask->delete();

                $taskHistory = new TaskHistory();
                $taskHistory->task_id = $task->id;
                $taskHistory->user_id = $user->id;
                $taskHistory->action = "$user->email" . " unarchived the task";
                $taskHistory->save();
            }

            $task->isArchived = $task->isArchived ? false : true;
            $task->save();

            return $this->sendResponse($task->toArray());
        } catch (Exception $exception) {
            Log::error($exception);
            return $this->sendError('Something went wrong, please contact administrator!', [], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function delete($id)
    {
        try {
            $user = Auth::user();
            $task = Task::find($id);

            if (!$task) {
                return $this->sendError('task not found!', [], Response::HTTP_NOT_FOUND);
            }

            $foundUser = BoardMembers::where("board_id", $task->status->board->id)->where("user_id", $user->id)->first();

            if (!$foundUser || $foundUser->role !== "Admin") {
                return $this->sendError("Not allowed to perform this action", [], Response::HTTP_METHOD_NOT_ALLOWED);
            }

            DB::beginTransaction();
            $task->delete();
            DB::commit();

            return $this->sendResponse([], Response::HTTP_NO_CONTENT);
        } catch (Exception $exception) {
            Log::error($exception);
            return $this->sendError('Something went wrong, please contact administrator!', [], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function changeTaskStatus(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                "board_id" => "required",
                "task_id" => "required|exists:tasks,id",
                "status" => "required|boolean"
            ]);

            if ($validator->fails()) {
                return $this->sendError($validator->messages()->toArray());
            }

            $authUser = Auth::user();
            $isUserAssignedToTask = TaskAssignedTo::where("assigned_to", $authUser->id)->where("task_id", $request->get("task_id"))->first();
            $getUserRole = BoardMembers::where("user_id", $authUser->id)->where("board_id", $request->get("board_id"))->first();

            if (!$getUserRole) {
                return $this->sendError("Not allowed to perform this action", [], Response::HTTP_METHOD_NOT_ALLOWED);
            }

            if (!$isUserAssignedToTask && $getUserRole->role !== "Admin") {
                return $this->sendError("Not allowed to perform this action", [], Response::HTTP_METHOD_NOT_ALLOWED);
            }

            $status = $request->get("status");
            $task = Task::find($request->get("task_id"));
            $task->isActive = $status;
            $task->save();

            $taskHistory = new TaskHistory();
            $taskHistory->task_id = $task->id;
            $taskHistory->user_id = $authUser->id;
            $taskHistory->action = "$authUser->email" . ' changed task status to ' . ($status ? 'active' : 'inactive');
            $taskHistory->save();

            return $this->sendResponse($task);
        } catch (Exception $exception) {
            Log::error($exception);
            return $this->sendError('Something went wrong, please contact administrator!', [], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    public function getTaskHistory($task_id)
    {
        try {
            $task = Task::find($task_id);

            if (!$task) {
                return $this->sendError('task not found!', [], Response::HTTP_NOT_FOUND);
            }

            $authUser = Auth::user();
            $foundUser = BoardMembers::where("board_id", $task->status->board->id)->where("user_id", $authUser->id)->first();

            if (!$foundUser) {
                return $this->sendError("Not allowed to perform this action", [], Response::HTTP_METHOD_NOT_ALLOWED);
            }

            $taskHistory = TaskHistory::where('task_id', $task->id)->orderBy("created_at", "DESC")->paginate(30);

            $result = [
                "task_history" => [],
                "currentPage" => $taskHistory->currentPage(),
                "hasMorePages" => $taskHistory->hasMorePages(),
                "lastPage" => $taskHistory->lastPage()
            ];

            foreach ($taskHistory as $task_history) {
                $result["task_history"][] = $task_history;
            }

            return $this->sendResponse($result);
        } catch (Exception $exception) {
            Log::error($exception);

            return $this->sendError('Something went wrong, please contact administrator!', [], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
