<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\FobiUser;

/**
 * Mailable Reset Password - Contoh Struktur
 * 
 * Mailable ini mengirim email reset password ke user.
 */
class ResetPassword extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * User yang request reset password
     */
    public $user;
    
    /**
     * Token reset password
     */
    public $token;
    
    /**
     * URL frontend (dari environment)
     */
    private $frontendUrl;

    /**
     * Create a new message instance.
     *
     * @param FobiUser $user
     * @param string $token
     */
    public function __construct(FobiUser $user, $token)
    {
        $this->user = $user;
        $this->token = $token;
        
        // Gunakan env() untuk URL, jangan hardcode!
        $this->frontendUrl = env('FRONTEND_URL', 'http://localhost:5173');
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        // Buat URL reset dengan token dan email
        $resetUrl = $this->frontendUrl . '/reset-password?' . http_build_query([
            'token' => $this->token,
            'email' => $this->user->email,
        ]);

        return $this->view('emails.reset-password')
                    ->subject('Reset Password - ' . config('app.name'))
                    ->with([
                        'resetUrl' => $resetUrl,
                        'user' => $this->user,
                        'expiresIn' => '60 menit', // Token expiry time
                    ]);
    }
}

/*
|--------------------------------------------------------------------------
| Email Template Example
|--------------------------------------------------------------------------
|
| Buat file: resources/views/emails/reset-password.blade.php
|
| <!DOCTYPE html>
| <html>
| <head>
|     <meta charset="utf-8">
|     <title>Reset Password</title>
| </head>
| <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
|     <div style="max-width: 600px; margin: 0 auto; padding: 20px;">
|         <h1 style="color: #2d3748;">Reset Password</h1>
|         
|         <p>Halo {{ $user->name }},</p>
|         
|         <p>Kami menerima permintaan untuk reset password akun Anda.</p>
|         
|         <p>Klik tombol di bawah untuk reset password:</p>
|         
|         <a href="{{ $resetUrl }}" style="
|             display: inline-block;
|             background-color: #4CAF50;
|             color: white;
|             padding: 12px 24px;
|             text-decoration: none;
|             border-radius: 5px;
|             margin: 20px 0;
|         ">
|             Reset Password
|         </a>
|         
|         <p style="color: #666; font-size: 14px;">
|             Link ini akan kadaluarsa dalam {{ $expiresIn }}.
|         </p>
|         
|         <p style="color: #666; font-size: 14px;">
|             Jika Anda tidak meminta reset password, abaikan email ini.
|         </p>
|         
|         <hr style="border: none; border-top: 1px solid #eee; margin: 20px 0;">
|         
|         <p style="color: #999; font-size: 12px;">
|             Email ini dikirim oleh {{ config('app.name') }}
|         </p>
|     </div>
| </body>
| </html>
|
*/
