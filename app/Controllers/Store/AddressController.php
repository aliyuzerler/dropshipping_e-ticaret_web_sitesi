<?php

declare(strict_types=1);

namespace App\Controllers\Store;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\AddressRepository;
use App\Services\Auth\CustomerAuthService;

/**
 * Adres defteri (IDOR korumalı): tüm okuma/yazma customer_id kapsamlı
 * AddressRepository metotlarıyla yapılır; sahiplik yoksa 404.
 *
 * Tam yol: app/Controllers/Store/AddressController.php
 */
final class AddressController
{
    private AddressRepository $addresses;

    private CustomerAuthService $auth;

    public function __construct(
        ?AddressRepository $addresses = null,
        ?CustomerAuthService $auth = null
    ) {
        $this->addresses = $addresses ?? new AddressRepository();
        $this->auth      = $auth ?? new CustomerAuthService();
    }

    public function index(Request $request): Response
    {
        $customerId = (int) $this->auth->currentCustomerId();

        return View::render('store/account/addresses', [
            'title'     => 'Adreslerim',
            'addresses' => $this->addresses->allForCustomer($customerId),
        ], 'layouts/store');
    }

    public function create(Request $request): Response
    {
        return $this->renderForm(null, []);
    }

    public function store(Request $request): Response
    {
        $customerId = (int) $this->auth->currentCustomerId();
        $input      = $this->collectInput($request->post());
        $errors     = $this->validate($input);

        if ($errors !== []) {
            Session::flash('old', $this->safeOld($request->post()));
            return $this->renderForm(null, $errors);
        }

        $this->addresses->createForCustomer($customerId, $this->toRow($input));
        flash('success', 'Adres kaydedildi.');
        return redirect('/hesabim/adresler');
    }

    public function edit(Request $request, string $id): Response
    {
        $customerId = (int) $this->auth->currentCustomerId();
        $address    = $this->findOwnedOrFail($id, $customerId);

        return $this->renderForm($address, []);
    }

    public function update(Request $request, string $id): Response
    {
        $customerId = (int) $this->auth->currentCustomerId();
        $address    = $this->findOwnedOrFail($id, $customerId);
        $addressId  = (int) $address['id'];

        $input  = $this->collectInput($request->post());
        $errors = $this->validate($input);

        if ($errors !== []) {
            Session::flash('old', $this->safeOld($request->post()));
            return $this->renderForm($address, $errors);
        }

        $this->addresses->updateForCustomer($addressId, $customerId, $this->toRow($input));
        flash('success', 'Adres güncellendi.');
        return redirect('/hesabim/adresler');
    }

    public function destroy(Request $request, string $id): Response
    {
        $customerId = (int) $this->auth->currentCustomerId();
        $address    = $this->findOwnedOrFail($id, $customerId);

        $this->addresses->deleteForCustomer((int) $address['id'], $customerId);
        flash('info', 'Adres silindi.');
        return redirect('/hesabim/adresler');
    }

    public function makeDefault(Request $request, string $id): Response
    {
        $customerId = (int) $this->auth->currentCustomerId();
        $address    = $this->findOwnedOrFail($id, $customerId);

        $this->addresses->setDefault((int) $address['id'], $customerId);
        flash('success', 'Varsayılan adres güncellendi.');
        return redirect('/hesabim/adresler');
    }

    /* ----- iç yardımcılar ----- */

    /** Sahiplik kontrolü — kayıt müşteriye ait değilse 404 (IDOR koruması). */
    private function findOwnedOrFail(string $id, int $customerId): array
    {
        $addressId = (int) $id;
        if ($addressId <= 0) {
            abort(404, 'Adres bulunamadı.');
        }
        $address = $this->addresses->findForCustomer($addressId, $customerId);
        if ($address === null) {
            abort(404, 'Adres bulunamadı.');
        }
        return $address;
    }

    /**
     * Ham POST → doğrulama girdisi.
     *
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    private function collectInput(array $post): array
    {
        return [
            'title'       => trim((string) ($post['title'] ?? '')),
            'full_name'   => trim((string) ($post['full_name'] ?? '')),
            'city'        => trim((string) ($post['city'] ?? '')),
            'district'    => trim((string) ($post['district'] ?? '')),
            'postal_code' => trim((string) ($post['postal_code'] ?? '')),
            'line1'       => trim((string) ($post['line1'] ?? '')),
            'line2'       => trim((string) ($post['line2'] ?? '')),
            'phone'       => trim((string) ($post['phone'] ?? '')),
            'is_default'  => !empty($post['is_default']) ? 1 : 0,
        ];
    }

    /**
     * Alan bazlı TÜRKÇE doğrulama.
     *
     * @param array<string,mixed> $input
     * @return array<string,list<string>>
     */
    private function validate(array $input): array
    {
        $errors = [];

        if ($input['full_name'] === '' || mb_strlen((string) $input['full_name'], 'UTF-8') > 160) {
            $errors['full_name'] = ['Ad Soyad zorunludur (en fazla 160 karakter).'];
        }
        if ($input['city'] === '' || mb_strlen((string) $input['city'], 'UTF-8') > 80) {
            $errors['city'] = ['İl zorunludur (en fazla 80 karakter).'];
        }
        if ($input['district'] === '' || mb_strlen((string) $input['district'], 'UTF-8') > 80) {
            $errors['district'] = ['İlçe zorunludur (en fazla 80 karakter).'];
        }
        if ($input['line1'] === '' || mb_strlen((string) $input['line1'], 'UTF-8') > 255) {
            $errors['line1'] = ['Açık adres zorunludur (en fazla 255 karakter).'];
        }
        if ($input['postal_code'] !== '' && mb_strlen((string) $input['postal_code'], 'UTF-8') > 16) {
            $errors['postal_code'] = ['Posta kodu en fazla 16 karakter olabilir.'];
        }
        if ($input['phone'] !== '' && mb_strlen((string) $input['phone'], 'UTF-8') > 40) {
            $errors['phone'] = ['Telefon en fazla 40 karakter olabilir.'];
        }
        if ($input['title'] !== '' && mb_strlen((string) $input['title'], 'UTF-8') > 80) {
            $errors['title'] = ['Adres başlığı en fazla 80 karakter olabilir.'];
        }

        return $errors;
    }

    /**
     * Doğrulanmış girdiyi DB satırına çevirir (opsiyonel alanlar NULL).
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function toRow(array $input): array
    {
        return [
            'title'       => $input['title'] !== '' ? $input['title'] : null,
            'type'        => 'shipping',
            'full_name'   => (string) $input['full_name'],
            'country_code' => 'TR',
            'city'        => (string) $input['city'],
            'district'    => (string) $input['district'],
            'postal_code' => $input['postal_code'] !== '' ? $input['postal_code'] : null,
            'line1'       => (string) $input['line1'],
            'line2'       => $input['line2'] !== '' ? $input['line2'] : null,
            'phone'       => $input['phone'] !== '' ? $input['phone'] : null,
            'is_default'  => (int) $input['is_default'],
        ];
    }

    /**
     * Create + edit ortak form şablonu.
     *
     * @param array<string,mixed>|null $address
     * @param array<string,list<string>> $errors
     */
    private function renderForm(?array $address, array $errors): Response
    {
        return View::render('store/account/address_form', [
            'title'   => $address === null ? 'Yeni Adres' : 'Adresi Düzenle',
            'address' => $address,
            'errors'  => $errors,
        ], 'layouts/store');
    }

    /**
     * Eski girdi flash'ı — hassas alan çıkarımı gerekmez (adres formunda şifre yok).
     *
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    private function safeOld(array $post): array
    {
        return $post;
    }
}
