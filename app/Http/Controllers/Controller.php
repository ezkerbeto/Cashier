<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;
}

function returnResponse($success, $message, $array = [], $throwable = null)
{
    $arrayResponse = ['success' => $success, 'message' => $message];
    if (!empty($array)) $arrayResponse += $array;
    if (isset($throwable)) $arrayResponse += ['error_file' => $throwable->getFile(), 'error_line' => $throwable->getLine(), 'error_message' => $throwable->getMessage()];
    return response()->json($arrayResponse);
}