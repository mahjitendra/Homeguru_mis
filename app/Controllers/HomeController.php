<?php

namespace App\Controllers;

class HomeController extends BaseController
{
    /**
     * Shows the home page.
     */
    public function index()
    {
        $data = [
            'title' => 'Welcome to HomeGuru',
            'welcome_message' => 'The Future of School Management is Here.'
        ];

        // The view method is inherited from BaseController
        // It will render 'app/Views/home/index.php' within the master layout
        $this->view('home.index', $data);
    }
}