<?php

declare(strict_types=1);

namespace App\Controllers\Store;

use App\Core\Logger;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\AddressRepository;
use App\Repositories\CustomerRepository;
use App\Repositories\OrderRepository;
use App\Services\Auth\CustomerAuthService;

/**
 * Hesabım: özet paneli + profil güncelleme + şifre değiştirme.
 * Tüm doğrulama hata mesajları TÜRKÇE ve alan bazlıdır
 * ($errors: array<string, list<string>>).
 *
 * Tam yol: app/Controllers/Store/AccountController.php
 */
final class AccountController
{
    private CustomerAuthService $auth;

    private CustomerRepository $customers;

    private OrderRepository $orders;

    private AddressRepository $addresses;

    public function __construct(
        ?CustomerAuthService $auth = null,
        ?CustomerRepository $customers = null,
        ?OrderRepository $orders = null,
        ?AddressRepository $addresses = null
    ) {
        $this->auth       = $auth ?? new CustomerAuthService();
        $this->customers  = $customers ?? new CustomerRepository();
        $this->orders     = $orders ?? new OrderRepository();
        $this->addresses  = $addresses ?? new AddressRepository();
    }

    public function dashboard(Request $request): Response
    {
        $customer = $this->auth->currentCustomer();
        if ($customer === null) {
            return redirect('/giris'); // middleware korumasına rağmen savunmacı çıkış
        }

        $customerId = (int) $customer['id'];

        return View::render('store/account/dashboard', [
            'title'        => 'Hesabım',
            'customer'     => $customer,
            'orderCount'   => $this->orders->countForCustomer($customerId),
            'addressCount' => count($this->addresses->allForCustomer($customerId)),
            'recentOrders' => $this->orders->forCustomer($customerId, 5),
            'errors'       => [],
        ], 'layouts/store');
    }

    public function updateProfile(Request $request): Response
    {
        $customer = $this->auth->currentCustomer();
        if ($customer === null) {
            return redirect('/giris');
        }
        $customerId = (int) $customer['id'];

        $firstName = trim($request->string('first_name'));
        $lastName  = trim($request->string('last_name'));
        $phone     = trim($request->string('phone'));

        $errors = [];
        if ($firstName === '' || mb_strlen($firstName, 'UTF-8') > 80) {
            $errors['first_name'] = ['Ad zorunludur (en fazla 80 karakter).'];
        }
        if ($lastName === '' || mb_strlen($lastName, 'UTF-8') > 80) {
            $errors['last_name'] = ['Soyad zorunludur (en fazla 80 karakter).'];
        }
        if ($phone !== '' && mb_strlen($phone, 'UTF-8') > 40) {
            $errors['phone'] = ['Telefon en fazla 40 karakter olabilir.'];
        }

        if ($errors !== []) {
            Session::flash('old', $this->safeOld($request->post()));
            return $this->renderDashboard($customer, $errors);
        }

        $this->customers->update($customerId, [
            'first_name' => $firstName,
            'last_name'  => $lastName,
            'phone'      => $phone !== '' ? $phone : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // Navbar'daki görünen ad da tazelensin.
        Session::set('customer_name', trim($firstName . ' ' . $lastName));

        flash('success', 'Profil bilgileriniz güncellendi.');
        return redirect('/hesabim');
    }

    public function updatePassword(Request $request): Response
    {
        $customer = $this->auth->currentCustomer();
        if ($customer === null) {
            return redirect('/giris');
        }

        $currentPassword = (string) ($request->post()['current_password'] ?? '');
        $newPassword     = (string) ($request->post()['password'] ?? '');
        $confirmation    = (string) ($request->post()['password_confirmation'] ?? '');

        $errors = [];
        $hash   = (string) ($customer['password_hash'] ?? '');
        if ($hash === '' || !password_verify($currentPassword, $hash)) {
            $errors['current_password'] = ['Mevcut şifreniz hatalı.'];
        }
        if (strlen($newPassword) < 8) {
            $errors['password'] = ['Yeni şifre en az 8 karakter olmalıdır.'];
        } elseif ($newPassword !== $confirmation) {
            $errors['password_confirmation'] = ['Şifreler eşleşmiyor.'];
        }

        if ($errors !== []) {
            Session::flash('old', $this->safeOld($request->post()));
            return $this->renderDashboard($customer, $errors);
        }

        $this->customers->updatePasswordHash((int) $customer['id'], CustomerAuthService::hashPassword($newPassword));
        Logger::info('app', 'customer.password_changed', ['customer_id' => (int) $customer['id']]);

        flash('success', 'Şifreniz güncellendi.');
        return redirect('/hesabim');
    }

    /**
     * Doğrulama hatasında paneli eski girdilerle yeniden render eder.
     *
     * @param array<string,mixed> $customer
     * @param array<string,list<string>> $errors
     */
    private function renderDashboard(array $customer, array $errors): Response
    {
        $customerId = (int) $customer['id'];

        return View::render('store/account/dashboard', [
            'title'        => 'Hesabım',
            'customer'     => $customer,
            'orderCount'   => $this->orders->countForCustomer($customerId),
            'addressCount' => count($this->addresses->allForCustomer($customerId)),
            'recentOrders' => $this->orders->forCustomer($customerId, 5),
            'errors'       => $errors,
        ], 'layouts/store');
    }

    /**
     * Eski girdi flash'ı — hassas alanlar (şifreler) çıkarılır.
     *
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    private function safeOld(array $post): array
    {
        unset($post['password'], $post['password_confirmation'], $post['current_password']);
        return $post;
    }
}
