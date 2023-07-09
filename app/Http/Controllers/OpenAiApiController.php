<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

class OpenAiApiController extends BaseController
{
    public function index(Request $request)
    {
        return response()->json([
            'message' => "I'm " . $request->input('name', 'OpenAI API')
        ]);
    }
}
