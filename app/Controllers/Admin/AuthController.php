<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\Auth\AdminAuthService;
use App\Validators\Validator;

/**
 * Admin giriş/çıkış.
 *
 * Tam yol: app/Controllers/Admin/AuthController.php
 */
final class AuthController
{
    public function showLogin(Request $request): Response
    {
        if ((new AdminAuthService())->check()) {
            return redirect('/admin');
        }
        return View::render('admin/login', [
            'title'   => 'Yönetici Girişi',
            'error'   => get_flash('login_error'),
            'email'   => get_flash('login_email', ''),
        ]);
    }

    public function login(Request $request): Response
    {
        $validator = Validator::make($request->post(), [
            'email'    => 'required|email|max:180',
            'password' => 'required|min:8|max:200',
        ]);

        try {
            $validator->validate();
        } catch (\App\Exceptions\ValidationException $e) {
            flash('login_error', $e->firstError());
            flash('login_email', $request->string('email'));
            return redirect('/admin/login');
        }

        $auth = new AdminAuthService();
        $ok = $auth->attempt(
            $request->string('email'),
            $request->string('password'),
            $request->ip(),
            $request->userAgent()
        );

        if (!$ok) {
            flash('login_error', 'E-posta veya şifre hatalı (çok fazla denemede hesap geçici olarak kilitlenir).');
            flash('login_email', $request->string('email'));
            return redirect('/admin/login');
        }

        Csrf::rotate();
        $intended = get_flash('intended');
        return redirect(is_string($intended) && str_starts_with($intended, '/admin') ? $intended : '/admin');
    }

    public function logout(Request $request): Response
    {
        (new AdminAuthService())->logout($request->ip(), $request->userAgent());
        return redirect('/admin/login');
    }
}
