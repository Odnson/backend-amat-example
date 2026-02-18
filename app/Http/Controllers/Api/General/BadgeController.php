<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\BadgeResource;
use App\Models\Badge;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class BadgeController extends Controller
{
    /**
     * Display a listing of badges with pagination, filtering, and search
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Badge::with('badgeType');

            // Search by title
            if ($request->filled('search')) {
                $search = $request->get('search');
                $query->where('title', 'LIKE', "%{$search}%");
            }

            // Filter by type
            if ($request->filled('type')) {
                $query->where('type', $request->get('type'));
            }

            // Filter by application flags
            if ($request->filled('fobi')) {
                $query->where('fobi', $request->get('fobi') === '1' || $request->get('fobi') === 'true');
            }
            if ($request->filled('burungnesia')) {
                $query->where('burungnesia', $request->get('burungnesia') === '1' || $request->get('burungnesia') === 'true');
            }
            if ($request->filled('kupunesia')) {
                $query->where('kupunesia', $request->get('kupunesia') === '1' || $request->get('kupunesia') === 'true');
            }
            if ($request->filled('akar')) {
                $query->where('akar', $request->get('akar') === '1' || $request->get('akar') === 'true');
            }

            // Order by created_at desc by default
            $query->orderBy('created_at', 'desc');

            // Pagination
            $perPage = $request->get('per_page', 15);
            $badges = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Badges retrieved successfully',
                'data' => BadgeResource::collection($badges),
                'meta' => [
                    'current_page' => $badges->currentPage(),
                    'last_page' => $badges->lastPage(),
                    'per_page' => $badges->perPage(),
                    'total' => $badges->total(),
                    'from' => $badges->firstItem(),
                    'to' => $badges->lastItem(),
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve badges',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created badge
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'title' => 'required|string|max:255|unique:badges,title',
                'icon_active' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
                'icon_unactive' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
                'images_congrats' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
                'text_congrats_1' => 'nullable|string|max:500',
                'text_congrats_2' => 'nullable|string|max:500',
                'text_congrats_3' => 'nullable|string|max:500',
                'type' => 'required|integer|exists:badge_types,id',
                'total' => 'nullable|integer|min:1',
                'fobi' => 'nullable|boolean',
                'burungnesia' => 'nullable|boolean',
                'kupunesia' => 'nullable|boolean',
                'akar' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Validate total is required for certain badge types
            $type = $request->get('type');
            $total = $request->get('total');
            $badgeType = \App\Models\BadgeType::find($type);
            
            if ($badgeType && $badgeType->requires_total && empty($total)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Total count is required for this badge type',
                    'errors' => ['total' => ['Total count is required for ' . $badgeType->name . ' badge type']]
                ], 422);
            }

            $badgeData = $request->only([
                'title', 'text_congrats_1', 'text_congrats_2', 'text_congrats_3', 'type', 'total'
            ]);

            // Set application flags with default values
            $badgeData['fobi'] = $request->has('fobi') ? (bool)$request->get('fobi') : false;
            $badgeData['burungnesia'] = $request->has('burungnesia') ? (bool)$request->get('burungnesia') : false;
            $badgeData['kupunesia'] = $request->has('kupunesia') ? (bool)$request->get('kupunesia') : false;
            $badgeData['akar'] = $request->has('akar') ? (bool)$request->get('akar') : false;

            // Handle file uploads
            if ($request->hasFile('icon_active')) {
                $badgeData['icon_active'] = $this->uploadFile($request->file('icon_active'), 'badges');
            }

            if ($request->hasFile('icon_unactive')) {
                $badgeData['icon_unactive'] = $this->uploadFile($request->file('icon_unactive'), 'badges');
            }

            if ($request->hasFile('images_congrats')) {
                $badgeData['images_congrats'] = $this->uploadFile($request->file('images_congrats'), 'badges');
            }

            $badge = Badge::create($badgeData);

            return response()->json([
                'success' => true,
                'message' => 'Badge created successfully',
                'data' => new BadgeResource($badge)
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create badge',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified badge
     *
     * @param int $id
     * @return JsonResponse
     */
    public function show(int $id): JsonResponse
    {
        try {
            $badge = Badge::with('badgeType')->findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'Badge retrieved successfully',
                'data' => new BadgeResource($badge)
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Badge not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve badge',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified badge
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $badge = Badge::with('badgeType')->findOrFail($id);

            $validator = Validator::make($request->all(), [
                'title' => 'sometimes|required|string|max:255|unique:badges,title,' . $id,
                'icon_active' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
                'icon_unactive' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
                'images_congrats' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
                'text_congrats_1' => 'nullable|string|max:500',
                'text_congrats_2' => 'nullable|string|max:500',
                'text_congrats_3' => 'nullable|string|max:500',
                'type' => 'sometimes|required|integer|exists:badge_types,id',
                'total' => 'nullable|integer|min:1',
                'fobi' => 'nullable|boolean',
                'burungnesia' => 'nullable|boolean',
                'kupunesia' => 'nullable|boolean',
                'akar' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Validate total is required for certain badge types if type is being updated
            $type = $request->get('type', $badge->type);
            $total = $request->get('total', $badge->total);
            $badgeType = \App\Models\BadgeType::find($type);
            
            if ($badgeType && $badgeType->requires_total && empty($total)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Total count is required for this badge type',
                    'errors' => ['total' => ['Total count is required for ' . $badgeType->name . ' badge type']]
                ], 422);
            }

            $badgeData = $request->only([
                'title', 'text_congrats_1', 'text_congrats_2', 'text_congrats_3', 'type', 'total'
            ]);

            // Set application flags with default values
            $badgeData['fobi'] = $request->has('fobi') ? (bool)$request->get('fobi') : $badge->fobi;
            $badgeData['burungnesia'] = $request->has('burungnesia') ? (bool)$request->get('burungnesia') : $badge->burungnesia;
            $badgeData['kupunesia'] = $request->has('kupunesia') ? (bool)$request->get('kupunesia') : $badge->kupunesia;
            $badgeData['akar'] = $request->has('akar') ? (bool)$request->get('akar') : $badge->akar;

            // Handle file uploads and delete old files
            if ($request->hasFile('icon_active')) {
                if ($badge->icon_active) {
                    $this->deleteFile($badge->icon_active, 'badges');
                }
                $badgeData['icon_active'] = $this->uploadFile($request->file('icon_active'), 'badges');
            }

            if ($request->hasFile('icon_unactive')) {
                if ($badge->icon_unactive) {
                    $this->deleteFile($badge->icon_unactive, 'badges');
                }
                $badgeData['icon_unactive'] = $this->uploadFile($request->file('icon_unactive'), 'badges');
            }

            if ($request->hasFile('images_congrats')) {
                if ($badge->images_congrats) {
                    $this->deleteFile($badge->images_congrats, 'badges');
                }
                $badgeData['images_congrats'] = $this->uploadFile($request->file('images_congrats'), 'badges');
            }

            $badge->update($badgeData);

            return response()->json([
                'success' => true,
                'message' => 'Badge updated successfully',
                'data' => new BadgeResource($badge->fresh())
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Badge not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update badge',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified badge (soft delete)
     *
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $badge = Badge::findOrFail($id);
            $badge->delete();

            return response()->json([
                'success' => true,
                'message' => 'Badge deleted successfully'
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Badge not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete badge',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload file to storage
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @param string $folder
     * @return string
     */
    private function uploadFile($file, string $folder): string
    {
        $filename = time() . '_' . Str::random(10) . '.' . $file->getClientOriginalExtension();
        $file->storeAs("public/{$folder}", $filename);
        return $filename;
    }

    /**
     * Get badges for specific application
     *
     * @param Request $request
     * @param string $app
     * @return JsonResponse
     */
    public function getByApplication(Request $request, string $app): JsonResponse
    {
        try {
            $allowedApps = ['fobi', 'burungnesia', 'kupunesia', 'akar'];
            
            if (!in_array($app, $allowedApps)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid application name. Allowed: ' . implode(', ', $allowedApps)
                ], 400);
            }

            $query = Badge::with('badgeType')->where($app, true);

            // Search by title
            if ($request->filled('search')) {
                $search = $request->get('search');
                $query->where('title', 'LIKE', "%{$search}%");
            }

            // Filter by type
            if ($request->filled('type')) {
                $query->where('type', $request->get('type'));
            }

            // Order by created_at desc by default
            $query->orderBy('created_at', 'desc');

            // Pagination
            $perPage = $request->get('per_page', 15);
            $badges = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => "Badges for {$app} retrieved successfully",
                'data' => BadgeResource::collection($badges),
                'meta' => [
                    'application' => $app,
                    'current_page' => $badges->currentPage(),
                    'last_page' => $badges->lastPage(),
                    'per_page' => $badges->perPage(),
                    'total' => $badges->total(),
                    'from' => $badges->firstItem(),
                    'to' => $badges->lastItem(),
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve badges',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user badge progress for specific application
     *
     * @param Request $request
     * @param string $app
     * @return JsonResponse
     */
    public function getUserProgress(Request $request, string $app): JsonResponse
    {
        try {
            $allowedApps = ['fobi', 'burungnesia', 'kupunesia', 'akar'];
            
            if (!in_array($app, $allowedApps)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid application name. Allowed: ' . implode(', ', $allowedApps)
                ], 400);
            }

            $validator = Validator::make($request->all(), [
                'user_id' => 'required|integer',
                'current_count' => 'required|integer|min:0',
                'type' => 'nullable|integer|in:1,2',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $userId = $request->get('user_id');
            $currentCount = $request->get('current_count');
            $type = $request->get('type');

            $query = Badge::with('badgeType')->where($app, true);
            
            if ($type) {
                $query->where('type', $type);
            }

            $badges = $query->orderBy('total', 'asc')->get();

            $result = [];
            foreach ($badges as $badge) {
                $isEarned = $currentCount >= $badge->total;
                $progress = min(100, ($currentCount / $badge->total) * 100);

                $result[] = [
                    'badge' => new BadgeResource($badge),
                    'progress' => [
                        'current_count' => $currentCount,
                        'target_count' => $badge->total,
                        'percentage' => round($progress, 2),
                        'is_earned' => $isEarned,
                        'remaining' => max(0, $badge->total - $currentCount)
                    ]
                ];
            }

            return response()->json([
                'success' => true,
                'message' => "User progress for {$app} retrieved successfully",
                'data' => $result,
                'meta' => [
                    'application' => $app,
                    'user_id' => $userId,
                    'current_count' => $currentCount,
                    'total_badges' => count($result),
                    'earned_badges' => count(array_filter($result, fn($item) => $item['progress']['is_earned']))
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve user progress',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check if user earned new badges
     *
     * @param Request $request
     * @param string $app
     * @return JsonResponse
     */
    public function checkNewBadges(Request $request, string $app): JsonResponse
    {
        try {
            $allowedApps = ['fobi', 'burungnesia', 'kupunesia', 'akar'];
            
            if (!in_array($app, $allowedApps)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid application name. Allowed: ' . implode(', ', $allowedApps)
                ], 400);
            }

            $validator = Validator::make($request->all(), [
                'user_id' => 'required|integer',
                'current_count' => 'required|integer|min:0',
                'previous_count' => 'required|integer|min:0',
                'type' => 'nullable|integer|in:1,2',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $userId = $request->get('user_id');
            $currentCount = $request->get('current_count');
            $previousCount = $request->get('previous_count');
            $type = $request->get('type');

            $query = Badge::with('badgeType')
                         ->where($app, true)
                         ->where('total', '>', $previousCount)
                         ->where('total', '<=', $currentCount);
            
            if ($type) {
                $query->where('type', $type);
            }

            $newBadges = $query->orderBy('total', 'asc')->get();

            $result = [];
            foreach ($newBadges as $badge) {
                $result[] = new BadgeResource($badge);
            }

            return response()->json([
                'success' => true,
                'message' => count($result) > 0 ? 'New badges earned!' : 'No new badges earned',
                'data' => $result,
                'meta' => [
                    'application' => $app,
                    'user_id' => $userId,
                    'current_count' => $currentCount,
                    'previous_count' => $previousCount,
                    'new_badges_count' => count($result),
                    'has_new_badges' => count($result) > 0
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check new badges',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get available badge types
     *
     * @return JsonResponse
     */
    public function getBadgeTypes(): JsonResponse
    {
        try {
            $types = Badge::getBadgeTypes();
            $typesWithTotal = Badge::getTypesWithTotal();
            
            $result = [];
            foreach ($types as $id => $name) {
                $result[] = [
                    'id' => $id,
                    'name' => $name,
                    'requires_total' => in_array($id, $typesWithTotal),
                    'description' => $this->getBadgeTypeDescription($id)
                ];
            }

            return response()->json([
                'success' => true,
                'message' => 'Badge types retrieved successfully',
                'data' => $result
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve badge types',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get badge type description
     *
     * @param int $type
     * @return string
     */
    private function getBadgeTypeDescription(int $type): string
    {
        $descriptions = [
            1 => 'Badge berdasarkan jumlah checklist yang diselesaikan',
            2 => 'Badge berdasarkan jumlah spesies yang ditemukan',
            3 => 'Badge pencapaian khusus tanpa target angka',
            4 => 'Badge berdasarkan durasi waktu aktivitas',
            5 => 'Badge untuk event atau acara khusus',
            6 => 'Badge milestone berdasarkan pencapaian tertentu',
            7 => 'Badge berdasarkan jumlah kontribusi',
            8 => 'Badge berdasarkan kualitas kontribusi',
            9 => 'Badge aktivitas komunitas',
            10 => 'Badge tingkat keahlian'
        ];

        return $descriptions[$type] ?? 'Badge custom';
    }

    /**
     * Get badge statistics for all applications
     *
     * @return JsonResponse
     */
    public function statistics(): JsonResponse
    {
        try {
            $stats = [
                'total_badges' => Badge::count(),
                'by_type' => [
                    'checklist' => Badge::where('type', 1)->count(),
                    'species' => Badge::where('type', 2)->count(),
                ],
                'by_application' => [
                    'fobi' => Badge::where('fobi', true)->count(),
                    'burungnesia' => Badge::where('burungnesia', true)->count(),
                    'kupunesia' => Badge::where('kupunesia', true)->count(),
                    'akar' => Badge::where('akar', true)->count(),
                ],
                'recent_badges' => Badge::where('created_at', '>=', now()->subDays(7))->count(),
            ];

            return response()->json([
                'success' => true,
                'message' => 'Badge statistics retrieved successfully',
                'data' => $stats
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete file from storage
     *
     * @param string $filename
     * @param string $folder
     * @return void
     */
    private function deleteFile(string $filename, string $folder): void
    {
        if (Storage::exists("public/{$folder}/{$filename}")) {
            Storage::delete("public/{$folder}/{$filename}");
        }
    }
}
