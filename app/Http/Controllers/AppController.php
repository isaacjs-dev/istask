<?php

namespace App\Http\Controllers;

use App\Support\TaskRepository;

class AppController extends Controller
{
    public function index(TaskRepository $repo)
    {
        return view('app', ['boot' => $repo->bootstrap()]);
    }
}
