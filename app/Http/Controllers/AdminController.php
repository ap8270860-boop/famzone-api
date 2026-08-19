<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function adminDashboard(Request $request)
    {
        return view('back.pages.admin.dashboard');
    }
}
