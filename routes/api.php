<?php

use App\Models\Task;
use App\Models\Image;
use Aws\S3\S3Client;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::get('/tasks', function () {
    $tasks = Task::with('images')->orderBy('createdAt', 'asc')->get();

    return response()->json([
        'status' => 200,
        'data' => $tasks
    ]);
});

/**
 * Add A New Task
 */
Route::post('/task', function (Request $request) {
    $validator = Validator::make($request->all(), [
        'content' => 'required|max:255',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => 422,
            'error' => $validator->errors(),
        ]);
    }

    $task = new Task;
    $task->content = $request->content;
    $task->save();

    return response()->json([
        'status' => 200,
        'data' => [
            'taskId' => $task->taskId,
        ],
    ]);
});

/**
 * Delete An Existing Task
 */
Route::delete('/task/{taskId}', function ($taskId) {
    $task = Task::findOrFail($taskId);
    $images = $task->images;
    $files = [];
    foreach ($images as $image) {
        $files[] = ['Key' => pathinfo($image->url)['basename']];
    }
    if (!empty($files)) {
        $s3 = S3Client::factory([
            'region' => 'us-west-2',
            'version' => '2006-03-01',
        ]);
        $s3->deleteObjects([
            'Bucket' => config('image.bucket'),
            'Delete' => [
                'Objects' => $files,
            ],
        ]);
        $task->images()->delete();
    }
    $task->delete();

    return response()->json([
        'status' => 200,
        'data' => [],
    ]);
});


Route::post('/image', function (Request $request) {
    $validator = Validator::make($request->all(), [
        'image' => 'required',
        'taskId' => 'required|integer|min:1'
    ]);

    if ($validator->fails()) {
        return response()->json([
            'status' => 422,
            'error' => $validator->errors(),
        ]);
    }
    $file = $request->file('image');
    $s3 = S3Client::factory([
        'region' => 'us-west-2',
        'version' => '2006-03-01',
    ]);
    $fileName = sprintf('%s.%s', time(), $file->extension());
    $result = $s3->putObject([
        'Bucket' => config('image.bucket'),
        'Key' => $fileName,
        'SourceFile' => $file,
        'ACL' => 'public-read',
    ]);

    $image = new Image;
    $image->taskId = $request->get('taskId');
    $image->url = $fileName;
    $image->save();

    return response()->json([
        'status' => 200,
        'data' => [
            'imageId' => $image->imageId,
            'url' => $image->url,
        ],
    ]);
});

Route::delete('/image/{imageId}', function ($imageId, Request $request) {
    $image = Image::findOrFail($imageId);
    $s3 = S3Client::factory([
        'region' => 'us-west-2',
        'version' => '2006-03-01',
    ]);
    $s3->deleteObject([
        'Bucket' => config('image.bucket'),
        'Key' => pathinfo($image->url)['basename'],
    ]);
    $image->delete();

    return response()->json([
        'status' => 200,
        'data' => [],
    ]);
});
