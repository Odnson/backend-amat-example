<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TaxaNamingFlag;
use App\Models\Taxa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;

class TaxaNamingFlagController extends Controller
{
    /**
     * Submit a new taxa naming flag/report
     */
    public function store(Request $request)
    {
        try {
            // Validation rules - allow null taxa_id for missing taxa reports
            $validator = Validator::make($request->all(), [
                'taxa_id' => 'nullable|exists:taxas,id',
                'taxa_name' => 'nullable|string|max:255', // Make taxa_name optional for now
                'flag_type' => 'required|in:incorrect_scientific_name,incorrect_common_name,missing_common_name,incorrect_taxonomy,incomplete_taxa,duplicate_entry,missing_taxa,other',
                'reason' => 'required|string|max:2000',
                'suggested_correction' => 'nullable|string|max:1000',
                'user_name' => 'nullable|string|max:255',
                'user_email' => 'nullable|email|max:255',
                'additional_data' => 'nullable|array'
            ]);
            
            // Custom validation: require either taxa_id OR taxa_name
            if (!$request->taxa_id && !$request->taxa_name) {
                return response()->json([
                    'success' => false,
                    'message' => 'Either taxa_id or taxa_name is required',
                    'errors' => [
                        'taxa_id' => ['Either taxa_id or taxa_name must be provided'],
                        'taxa_name' => ['Either taxa_id or taxa_name must be provided']
                    ]
                ], 422);
            }

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data tidak valid',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Get authenticated user if available
            $user = null;
            try {
                $user = JWTAuth::parseToken()->authenticate();
            } catch (\Exception $e) {
                // User not authenticated, allow anonymous reporting
            }

            // Validate taxa exists (only if taxa_id is provided)
            $taxa = null;
            $taxaName = null;
            
            if ($request->taxa_id) {
                $taxa = Taxa::find($request->taxa_id);
                if (!$taxa) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Taxa tidak ditemukan'
                    ], 404);
                }
                $taxaName = $taxa->scientific_name;
            } else {
                // For missing taxa reports, use the provided taxa_name
                $taxaName = $request->taxa_name;
            }

            // Prepare flag data
            $flagData = [
                'taxa_id' => $request->taxa_id,
                'flag_type' => $request->flag_type,
                'reason' => $request->reason,
                'suggested_correction' => $request->suggested_correction,
                'additional_data' => $request->additional_data,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent()
            ];
            
            // Only add taxa_name if the column exists (after migration)
            if (Schema::hasColumn('taxa_naming_flags', 'taxa_name')) {
                $flagData['taxa_name'] = $taxaName;
            }

            // Add user information
            if ($user) {
                $flagData['user_id'] = $user->id;
            } else {
                // Anonymous user
                $flagData['user_name'] = $request->user_name;
                $flagData['user_email'] = $request->user_email;
            }

            // Create the flag
            $flag = TaxaNamingFlag::create($flagData);

            // Log the activity
            Log::info('Taxa naming flag submitted', [
                'flag_id' => $flag->id,
                'taxa_id' => $request->taxa_id,
                'taxa_name' => $taxaName,
                'flag_type' => $request->flag_type,
                'user_id' => $user ? $user->id : null,
                'user_name' => $user ? $user->uname : $request->user_name,
                'ip_address' => $request->ip()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Laporan berhasil dikirim. Terima kasih atas kontribusi Anda!',
                'data' => [
                    'flag_id' => $flag->id,
                    'taxa_name' => $taxaName,
                    'flag_type' => $flag->flag_type_display
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('Error submitting taxa naming flag', [
                'error' => $e->getMessage(),
                'taxa_id' => $request->taxa_id ?? null,
                'flag_type' => $request->flag_type ?? null,
                'ip_address' => $request->ip()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengirim laporan. Silakan coba lagi.'
            ], 500);
        }
    }

    /**
     * Get flags for a specific taxa
     */
    public function getByTaxa($taxaId)
    {
        try {
            $taxa = Taxa::find($taxaId);
            if (!$taxa) {
                return response()->json([
                    'success' => false,
                    'message' => 'Taxa tidak ditemukan'
                ], 404);
            }

            $flags = TaxaNamingFlag::with(['user', 'resolver'])
                ->where('taxa_id', $taxaId)
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($flag) {
                    return [
                        'id' => $flag->id,
                        'flag_type' => $flag->flag_type,
                        'flag_type_display' => $flag->flag_type_display,
                        'reason' => $flag->reason,
                        'suggested_correction' => $flag->suggested_correction,
                        'is_resolved' => $flag->is_resolved,
                        'resolution_notes' => $flag->resolution_notes,
                        'reporter_name' => $flag->reporter_name,
                        'resolved_by' => $flag->resolver ? $flag->resolver->uname : null,
                        'created_at' => $flag->created_at->format('Y-m-d H:i:s'),
                        'resolved_at' => $flag->resolved_at ? $flag->resolved_at->format('Y-m-d H:i:s') : null
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => [
                    'taxa' => [
                        'id' => $taxa->id,
                        'scientific_name' => $taxa->scientific_name,
                        'common_name' => $taxa->cname
                    ],
                    'flags' => $flags,
                    'total_flags' => $flags->count(),
                    'unresolved_flags' => $flags->where('is_resolved', false)->count()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting taxa flags', [
                'error' => $e->getMessage(),
                'taxa_id' => $taxaId
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil data laporan'
            ], 500);
        }
    }

    /**
     * Get flag types for frontend
     */
    public function getFlagTypes()
    {
        return response()->json([
            'success' => true,
            'data' => TaxaNamingFlag::getFlagTypes()
        ]);
    }

    /**
     * Get statistics
     */
    public function getStats()
    {
        try {
            $stats = [
                'total_flags' => TaxaNamingFlag::count(),
                'unresolved_flags' => TaxaNamingFlag::getUnresolvedCount(),
                'resolved_flags' => TaxaNamingFlag::getResolvedCount(),
                'flags_by_type' => []
            ];

            // Get flags by type
            foreach (TaxaNamingFlag::getFlagTypes() as $type => $display) {
                $stats['flags_by_type'][$type] = [
                    'display' => $display,
                    'count' => TaxaNamingFlag::where('flag_type', $type)->count(),
                    'unresolved' => TaxaNamingFlag::where('flag_type', $type)->where('is_resolved', false)->count()
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);

        } catch (\Exception $e) {
            Log::error('Error getting taxa naming flag stats', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengambil statistik'
            ], 500);
        }
    }
}
