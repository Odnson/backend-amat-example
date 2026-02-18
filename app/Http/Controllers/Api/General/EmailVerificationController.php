<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BurungnesiaUser;
use App\Models\KupunesiaUser;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function verifyBurungnesiaEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email'
        ]);

        $email = $request->email;
        
        $exists = BurungnesiaUser::where('email', $email)->exists();

        return response()->json([
            'success' => true,
            'exists' => $exists,
            'email' => $email,
            'platform' => 'burungnesia'
        ]);
    }

    public function verifyKupunesiaEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email'
        ]);

        $email = $request->email;
        
        $exists = KupunesiaUser::where('email', $email)->exists();

        return response()->json([
            'success' => true,
            'exists' => $exists,
            'email' => $email,
            'platform' => 'kupunesia'
        ]);
    }
}
