# 📧 Mail Example

Folder ini berisi **contoh struktur Mailable** untuk referensi kontributor.

> **⚠️ CATATAN**: File mail asli ada di `app/Mail/` dan tidak di-include di repository publik untuk alasan privasi (berisi URL production).

## 📋 Mailable yang Tersedia

| Mailable | Deskripsi | View |
|----------|-----------|------|
| `ResetPassword.php` | Email reset password | `emails.reset-password` |
| `VerifyEmail.php` | Email verifikasi akun | `emails.verify-email` |

## 🔧 Cara Membuat Mailable

### 1. Generate Mailable
```bash
php artisan make:mail YourMailable
```

### 2. Struktur Dasar Mailable
```php
<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class YourMailable extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $data;

    public function __construct($user, $data = [])
    {
        $this->user = $user;
        $this->data = $data;
    }

    public function build()
    {
        return $this->view('emails.your-template')
                    ->subject('Your Subject')
                    ->with([
                        'user' => $this->user,
                        'data' => $this->data,
                    ]);
    }
}
```

### 3. Mengirim Email
```php
use App\Mail\YourMailable;
use Illuminate\Support\Facades\Mail;

// Kirim ke user
Mail::to($user->email)->send(new YourMailable($user, $data));

// Kirim dengan queue (recommended untuk production)
Mail::to($user->email)->queue(new YourMailable($user, $data));
```

## 📝 Template Email

Buat template di `resources/views/emails/`:

```blade
{{-- resources/views/emails/your-template.blade.php --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $subject ?? 'Email' }}</title>
</head>
<body>
    <h1>Halo, {{ $user->name }}!</h1>
    
    <p>{{ $data['message'] ?? '' }}</p>
    
    @if(isset($actionUrl))
    <a href="{{ $actionUrl }}" style="
        background-color: #4CAF50;
        color: white;
        padding: 10px 20px;
        text-decoration: none;
        border-radius: 5px;
    ">
        {{ $actionText ?? 'Klik di sini' }}
    </a>
    @endif
    
    <p>Terima kasih,<br>Tim {{ config('app.name') }}</p>
</body>
</html>
```

## 🔐 Best Practices

### 1. Gunakan Environment Variables untuk URL
```php
// ❌ JANGAN hardcode URL
$url = 'https://production-site.com/reset?token=' . $token;

// ✅ Gunakan env atau config
$url = config('app.frontend_url') . '/reset?token=' . $token;
// atau
$url = env('FRONTEND_URL') . '/reset?token=' . $token;
```

### 2. Validasi Email Sebelum Kirim
```php
if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
    Mail::to($email)->send(new YourMailable($user));
}
```

### 3. Handle Error
```php
try {
    Mail::to($user->email)->send(new YourMailable($user));
} catch (\Exception $e) {
    Log::error('Failed to send email: ' . $e->getMessage());
}
```

## 📧 Konfigurasi Mail

Pastikan `.env` sudah dikonfigurasi:

```env
MAIL_DRIVER=smtp
MAIL_HOST=smtp.mailtrap.io
MAIL_PORT=587
MAIL_USERNAME=your_username
MAIL_PASSWORD=your_password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@example.com
MAIL_FROM_NAME="${APP_NAME}"
```

### Untuk Development (Mailtrap)
1. Daftar di https://mailtrap.io
2. Buat inbox baru
3. Copy kredensial SMTP ke `.env`

### Untuk Production (Gmail)
```env
MAIL_DRIVER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your@gmail.com
MAIL_PASSWORD="your-app-password"
MAIL_ENCRYPTION=tls
```

> **Note**: Untuk Gmail, gunakan App Password, bukan password akun biasa.

## 📝 Contoh File

Lihat file contoh di folder ini:
- `ResetPassword.example.php` - Contoh mailable reset password
- `VerifyEmail.example.php` - Contoh mailable verifikasi email
