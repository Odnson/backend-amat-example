<?php

/**
 * ============================================================================
 * API Routes - Amaturalist Beta (Unified Version)
 * ============================================================================
 * 
 * Versi unified dari API routes yang menggabungkan:
 * - Clean architecture dengan Services
 * - Domain-based controller structure
 * 
 * Struktur namespace:
 * - App\Http\Controllers\Api\Auth
 * - App\Http\Controllers\Api\Observations
 * - App\Http\Controllers\Api\Checklists
 * - App\Http\Controllers\Api\Quality
 * - App\Http\Controllers\Api\Taxa
 * - App\Http\Controllers\Api\Users
 * - App\Http\Controllers\Api\Media
 * - App\Http\Controllers\Api\Gallery
 * - App\Http\Controllers\Api\Grid
 * - App\Http\Controllers\Api\General
 * 
 * @see app/Http/Controllers/Api/README.md
 * @see app/Services/README.md
 */

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// ============================================================================
// NAMESPACE IMPORTS - Organized by Domain
// ============================================================================

// Auth Controllers
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\Auth\BirdIdentificationController;
use App\Http\Controllers\Api\Auth\ButterflyIdentificationController;

// Observation Controllers
use App\Http\Controllers\Api\Observations\ObservationController;
use App\Http\Controllers\Api\Observations\FobiObservationApiController;
use App\Http\Controllers\Api\Observations\BurungnesiaObservationApiController;
use App\Http\Controllers\Api\Observations\KupunesiaObservationApiController;
use App\Http\Controllers\Api\Observations\KupunesiaObservationController;
use App\Http\Controllers\Api\Observations\FobiGeneralObservationController;
use App\Http\Controllers\Api\Observations\UnifiedObservationController;
use App\Http\Controllers\Api\Observations\FobiUserObservationController;
use App\Http\Controllers\Api\Observations\BirdObservationController;
use App\Http\Controllers\Api\Observations\ObservationShowController;

// Checklist Controllers
use App\Http\Controllers\Api\Checklists\ChecklistController;
use App\Http\Controllers\Api\Checklists\ChecklistDetailController;
use App\Http\Controllers\Api\Checklists\ChecklistObservationController;
use App\Http\Controllers\Api\Checklists\ChecklistCommentController;
use App\Http\Controllers\Api\Checklists\BurungnesiaChecklistController;
use App\Http\Controllers\Api\Checklists\KupunesiaChecklistController;
use App\Http\Controllers\Api\Checklists\FobiChecklistTaxaController;

// Quality Assessment Controllers
use App\Http\Controllers\Api\Quality\QualityAssessmentController;
use App\Http\Controllers\Api\Quality\ChecklistQualityAssessmentController;
use App\Http\Controllers\Api\Quality\TaxaQualityAssessmentController;
use App\Http\Controllers\Api\Quality\KupunesiaQualityAssessmentController;

// Taxa Controllers
use App\Http\Controllers\Api\Taxa\TaxaController;
use App\Http\Controllers\Api\Taxa\TaxaDetailController;
use App\Http\Controllers\Api\Taxa\TaxaNamingFlagController;
use App\Http\Controllers\Api\Taxa\TaxonBrowserController;
use App\Http\Controllers\Api\Taxa\SpeciesController;
use App\Http\Controllers\Api\Taxa\SpeciesSearchController;
use App\Http\Controllers\Api\Taxa\SpeciesSuggestionController;
use App\Http\Controllers\Api\Taxa\FavoriteTaxaController;

// User Controllers
use App\Http\Controllers\Api\Users\ProfileController;
use App\Http\Controllers\Api\Users\FobiUserController;
use App\Http\Controllers\Api\Users\FobiUserApiController;
use App\Http\Controllers\Api\Users\FollowController;

// Media Controllers
use App\Http\Controllers\Api\Media\AuthMediaController;
use App\Http\Controllers\Api\Media\FobiMediaController;
use App\Http\Controllers\Api\Media\SpectrogramController;
use App\Http\Controllers\Api\Media\GalleryController;

// Gallery Controllers
use App\Http\Controllers\Api\Gallery\SpeciesGalleryController;
use App\Http\Controllers\Api\Gallery\GenusGalleryController;
use App\Http\Controllers\Api\Gallery\FamilyGalleryController;
use App\Http\Controllers\Api\Gallery\OrderGalleryController;
use App\Http\Controllers\Api\Gallery\ClassGalleryController;
use App\Http\Controllers\Api\Gallery\PhylumGalleryController;
use App\Http\Controllers\Api\Gallery\KingdomGalleryController;
use App\Http\Controllers\Api\Gallery\TaxonGalleryController;

// Grid Controllers
use App\Http\Controllers\Api\Grid\GridCellController;
use App\Http\Controllers\Api\Grid\GridSpeciesController;
use App\Http\Controllers\Api\Grid\PolygonSpeciesController;
use App\Http\Controllers\Api\Grid\PolygonSidebarController;
use App\Http\Controllers\Api\Grid\MarkerController;
use App\Http\Controllers\Api\Grid\FobiMarkerController;
use App\Http\Controllers\Api\Grid\LocationLabelController;

// General Controllers
use App\Http\Controllers\Api\General\HomeController;
use App\Http\Controllers\Api\General\SearchController;
use App\Http\Controllers\Api\General\BadgeController;
use App\Http\Controllers\Api\General\NotificationController;
use App\Http\Controllers\Api\General\MessageController;
use App\Http\Controllers\Api\General\HistoryController;
use App\Http\Controllers\Api\General\StatisticsController;
use App\Http\Controllers\Api\General\CommentController;
use App\Http\Controllers\Api\General\UserReportController;
use App\Http\Controllers\Api\General\EmailVerificationController;
use App\Http\Controllers\Api\General\PageContentController;
use App\Http\Controllers\Api\General\SettingFaqController;

/*
|--------------------------------------------------------------------------
| API Routes - Refactored Structure
|--------------------------------------------------------------------------
|
| Struktur route yang telah diorganisir berdasarkan domain/fungsi.
| Setiap section dikelompokkan untuk kemudahan maintenance.
|
*/

// ============================================================================
// HEALTH CHECK
// ============================================================================

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'timestamp' => now()->toISOString(),
        'service' => 'AMATURALIST API',
        'version' => '1.0.0'
    ]);
});

// ============================================================================
// AUTHENTICATION ROUTES
// ============================================================================

Route::prefix('auth')->group(function () {
    // Public auth routes
    Route::post('/register', [FobiUserController::class, 'register']);
    Route::post('/login', [FobiUserController::class, 'login']);
    Route::post('/logout', [FobiUserController::class, 'logout']);
    Route::get('/refresh', [FobiUserController::class, 'refresh']);
    
    // Password reset
    Route::post('/forgot-password', [FobiUserController::class, 'forgotPassword']);
    Route::post('/reset-password', [FobiUserController::class, 'resetPassword']);
    
    // Email verification
    Route::get('/verify-email/{token}/{type}', [FobiUserController::class, 'verifyEmail']);
    Route::post('/verification-status', [FobiUserController::class, 'getVerificationStatus']);
    Route::post('/resend-verification', [FobiUserController::class, 'resendVerification']);
    
    // Platform email verification
    Route::post('/verify-burungnesia-email', [EmailVerificationController::class, 'verifyBurungnesiaEmail']);
    Route::post('/verify-kupunesia-email', [EmailVerificationController::class, 'verifyKupunesiaEmail']);
    
    // Token check (authenticated)
    Route::middleware('auth:api')->group(function () {
        Route::get('/check-token', [FobiUserController::class, 'checkToken']);
        Route::get('/user', function (Request $request) {
            return $request->user();
        });
    });
});

// ============================================================================
// USER MANAGEMENT ROUTES
// ============================================================================

Route::prefix('users')->group(function () {
    // Public user routes
    Route::get('/{id}/profile', [FobiUserController::class, 'getUserProfile']);
    
    // Admin user management (protected)
    Route::middleware(['jwt.verify', 'checkRole:3,4'])->group(function () {
        Route::get('/', [FobiUserApiController::class, 'index']);
        Route::get('/{id}', [FobiUserApiController::class, 'show']);
        Route::post('/', [FobiUserApiController::class, 'store']);
        Route::put('/{id}', [FobiUserApiController::class, 'update']);
        Route::delete('/{id}', [FobiUserApiController::class, 'destroy']);
    });
});

// ============================================================================
// PROFILE ROUTES
// ============================================================================

Route::prefix('profile')->group(function () {
    // Public profile routes
    Route::get('/home/{id}', [ProfileController::class, 'getHomeProfile']);
    Route::get('/stats/{id}', [ProfileController::class, 'getUserStats']);
    Route::get('/observations/{id}', [ProfileController::class, 'getUserObservations']);
    Route::get('/species/{id}', [ProfileController::class, 'getSpecies']);
    Route::get('/life-list/{id}', [ProfileController::class, 'getLifeList']);
    Route::get('/identifications/{id}', [ProfileController::class, 'getIdentifications']);
    Route::get('/dashboard/{id}', [ProfileController::class, 'getDashboard']);
    Route::get('/activity/{id}', [ProfileController::class, 'getActivity']);
    Route::get('/activities/{id}', [ProfileController::class, 'getUserActivities']);
    Route::get('/top-taxa/{id}', [ProfileController::class, 'getTopTaxa']);
    Route::get('/search-suggestions', [ProfileController::class, 'getSearchSuggestions']);
    Route::get('/grid-observations/{id}', [ProfileController::class, 'getGridObservations']);
    
    // Authenticated profile routes
    Route::middleware('auth:api')->group(function () {
        Route::get('/', [ProfileController::class, 'getUserProfile']);
        Route::post('/update', [ProfileController::class, 'update']);
        Route::post('/update-bio', [ProfileController::class, 'updateBio']);
        Route::post('/update-email', [ProfileController::class, 'updateEmail']);
        Route::post('/delete-account', [ProfileController::class, 'deleteAccount']);
        
        // Platform sync
        Route::post('/sync-platform-email/{platform}', [ProfileController::class, 'syncPlatformEmail']);
        Route::post('/resend-platform-verification/{platform}', [ProfileController::class, 'resendPlatformVerification']);
        Route::post('/unlink-platform-account/{platform}', [ProfileController::class, 'unlinkPlatformAccount']);
    });
    
    // Favorite Taxa
    Route::get('/favorite-taxas/{userId}', [FavoriteTaxaController::class, 'index']);
    Route::get('/taxa/search', [FavoriteTaxaController::class, 'searchTaxa']);
    Route::get('/taxa/rank-values', [FavoriteTaxaController::class, 'getTaxonomicRankValues']);
    Route::get('/taxa/by-hierarchy', [FavoriteTaxaController::class, 'getTaxaByHierarchy']);
    
    Route::middleware('auth:api')->group(function () {
        Route::post('/favorite-taxas', [FavoriteTaxaController::class, 'store']);
        Route::delete('/favorite-taxas/{id}', [FavoriteTaxaController::class, 'destroy']);
    });
});

// ============================================================================
// FOLLOW ROUTES
// ============================================================================

Route::prefix('follow')->group(function () {
    Route::get('/followers/{userId}', [FollowController::class, 'getFollowers']);
    Route::get('/following/{userId}', [FollowController::class, 'getFollowing']);
    
    Route::middleware('auth:api')->group(function () {
        Route::get('/status/{userId}', [FollowController::class, 'checkFollowStatus']);
        Route::post('/{userId}', [FollowController::class, 'follow']);
        Route::delete('/{userId}', [FollowController::class, 'unfollow']);
    });
});

// ============================================================================
// OBSERVATION ROUTES
// ============================================================================

Route::prefix('observations')->group(function () {
    // Public observation routes
    Route::get('/general', [FobiGeneralObservationController::class, 'getObservations']);
    Route::get('/birds', [FobiObservationApiController::class, 'getObservations']);
    Route::get('/butterflies', [KupunesiaObservationApiController::class, 'getObservations']);
    Route::get('/unified', [UnifiedObservationController::class, 'getObservations']);
    Route::get('/needs-id', [ChecklistObservationController::class, 'getNeedsIdObservations']);
    Route::get('/search', [FobiGeneralObservationController::class, 'searchObservations']);
    Route::get('/{id}/simple', [ChecklistDetailController::class, 'getDetail']);
    Route::get('/{id}/assess-quality', [ChecklistQualityAssessmentController::class, 'assessQuality']);
    
    // Authenticated observation routes
    Route::middleware('auth:api')->group(function () {
        // Create & Upload
        Route::post('/', [FobiGeneralObservationController::class, 'store']);
        Route::post('/generate-spectrogram', [FobiGeneralObservationController::class, 'generateSpectrogram']);
        Route::post('/crop-image', [FobiGeneralObservationController::class, 'cropImage']);
        
        // Detail & Update
        Route::get('/{id}', [ChecklistObservationController::class, 'getObservationDetail']);
        Route::put('/{id}', [ChecklistDetailController::class, 'update']);
        Route::delete('/{id}', [ChecklistDetailController::class, 'destroy']);
        
        // Related locations
        Route::get('/related-locations/{taxaId}', [ChecklistObservationController::class, 'getRelatedLocations']);
        
        // Identification
        Route::post('/{id}/identifications', [ChecklistObservationController::class, 'addIdentification']);
        Route::post('/{checklistId}/identifications/{identificationId}/agree', [ChecklistObservationController::class, 'agreeWithIdentification']);
        Route::post('/{checklistId}/identifications/{identificationId}/disagree', [ChecklistObservationController::class, 'disagreeWithIdentification']);
        Route::post('/{checklistId}/identifications/{identificationId}/withdraw', [ChecklistObservationController::class, 'withdrawIdentification']);
        Route::post('/{checklistId}/identifications/{identificationId}/cancel-agreement', [ChecklistObservationController::class, 'cancelAgreement']);
        
        // Quality Assessment
        Route::get('/{id}/quality-assessment', [ChecklistQualityAssessmentController::class, 'getQualityAssessment']);
        Route::put('/{id}/quality-assessment/{criteria}', [ChecklistQualityAssessmentController::class, 'updateQualityAssessment']);
        Route::put('/{id}/improvement-status', [ChecklistQualityAssessmentController::class, 'updateImprovementStatus']);
        Route::post('/{id}/rate', [ChecklistQualityAssessmentController::class, 'rateChecklist']);
        Route::get('/{id}/confidence', [ChecklistQualityAssessmentController::class, 'getConfidenceData']);
        
        // Verification
        Route::post('/{id}/curator-verify', [ChecklistObservationController::class, 'curatorVerification']);
        Route::post('/{id}/verify-location', [ChecklistObservationController::class, 'verifyLocation']);
        Route::post('/{id}/vote-wild', [ChecklistObservationController::class, 'voteWildStatus']);
        
        // Comments
        Route::get('/{id}/comments', [ChecklistObservationController::class, 'getComments']);
        Route::post('/{id}/comments', [ChecklistObservationController::class, 'addComment']);
        Route::delete('/{id}/comments/{commentId}', [ChecklistObservationController::class, 'deleteComment']);
        Route::post('/{id}/comments/{commentId}/flag', [ChecklistObservationController::class, 'flagComment']);
        
        // Flags
        Route::post('/{id}/flag', [ChecklistObservationController::class, 'addFlag']);
        Route::get('/{id}/flags', [ChecklistObservationController::class, 'getFlags']);
        Route::post('/{id}/flags/{flagId}/resolve', [ChecklistObservationController::class, 'resolveFlag']);
        
        // License
        Route::put('/{id}/license', [ChecklistObservationController::class, 'updateObservationLicense']);
        
        // Fauna management
        Route::delete('/{checklistId}/fauna/{faunaId}', [ChecklistDetailController::class, 'deleteFauna']);
        Route::delete('/{checklistId}/fauna/all', [ChecklistDetailController::class, 'deleteAllFauna']);
    });
});

// ============================================================================
// USER OBSERVATIONS ROUTES
// ============================================================================

Route::prefix('user-observations')->middleware('auth:api')->group(function () {
    Route::get('/', [FobiUserObservationController::class, 'getUserObservations']);
    Route::get('/{id}', [FobiUserObservationController::class, 'getObservationDetail']);
    Route::put('/{id}', [FobiUserObservationController::class, 'updateObservation']);
    Route::delete('/{id}', [FobiUserObservationController::class, 'deleteObservation']);
    Route::get('/search-suggestions', [FobiUserObservationController::class, 'getSearchSuggestions']);
    
    // Media management
    Route::post('/{id}/media', [FobiMediaController::class, 'addMedia']);
    Route::delete('/{id}/media', [FobiMediaController::class, 'deleteMedia']);
});

// ============================================================================
// BURUNGNESIA ROUTES
// ============================================================================

Route::prefix('burungnesia')->group(function () {
    // Public routes
    Route::get('/observations/search', [FobiObservationApiController::class, 'searchObservations']);
    Route::get('/checklists/{id}', [BurungnesiaChecklistController::class, 'getDetail']);
    Route::get('/media/{mediaId}/comments', [BurungnesiaChecklistController::class, 'getMediaComments']);
    Route::get('/media/{mediaId}/rating', [BurungnesiaChecklistController::class, 'getMediaRating']);
    
    // Authenticated routes
    Route::middleware('auth:api')->group(function () {
        Route::post('/checklist-fauna', [BurungnesiaObservationApiController::class, 'storeChecklistAndFauna']);
        Route::get('/observations', [BurungnesiaObservationApiController::class, 'getObservations']);
        Route::get('/observations/{id}', [BurungnesiaObservationApiController::class, 'getObservationDetail']);
        Route::put('/checklists/{id}', [BurungnesiaChecklistController::class, 'update']);
        
        // Identification
        Route::post('/observations/{id}/identify', [BurungnesiaObservationApiController::class, 'addIdentification']);
        Route::post('/observations/{checklistId}/identifications/{identificationId}/agree', [BurungnesiaObservationApiController::class, 'agreeWithIdentification']);
        Route::post('/observations/{checklistId}/identifications/{identificationId}/disagree', [BurungnesiaObservationApiController::class, 'disagreeWithIdentification']);
        Route::post('/observations/{checklistId}/identifications/{identificationId}/withdraw', [BurungnesiaObservationApiController::class, 'withdrawIdentification']);
        
        // Media
        Route::post('/media/{mediaId}/comments', [BurungnesiaChecklistController::class, 'addMediaComment']);
        Route::post('/media/{mediaId}/rating', [BurungnesiaChecklistController::class, 'rateMedia']);
    });
});

// ============================================================================
// KUPUNESIA ROUTES
// ============================================================================

Route::prefix('kupunesia')->group(function () {
    // Public routes
    Route::get('/observations/search', [KupunesiaObservationApiController::class, 'searchObservations']);
    Route::get('/checklists/{id}', [KupunesiaChecklistController::class, 'getDetail']);
    Route::get('/media/{mediaId}/comments', [KupunesiaChecklistController::class, 'getMediaComments']);
    Route::get('/media/{mediaId}/rating', [KupunesiaChecklistController::class, 'getMediaRating']);
    
    // Authenticated routes
    Route::middleware('auth:api')->group(function () {
        Route::get('/faunas', [KupunesiaObservationApiController::class, 'getFaunaId']);
        Route::post('/checklist-fauna', [KupunesiaObservationApiController::class, 'storeChecklistAndFauna']);
        Route::post('/generate-spectrogram', [KupunesiaObservationApiController::class, 'generateSpectrogram']);
        Route::put('/checklists/{id}', [KupunesiaChecklistController::class, 'update']);
        
        // Identification & Verification
        Route::post('/observations/{id}/identify', [KupunesiaObservationApiController::class, 'addIdentification']);
        Route::post('/observations/{id}/verify-location', [KupunesiaObservationApiController::class, 'verifyLocation']);
        Route::post('/observations/{id}/vote-wild', [KupunesiaObservationApiController::class, 'voteWildStatus']);
        Route::post('/observations/{id}/verify-evidence', [KupunesiaObservationApiController::class, 'verifyEvidence']);
        
        // Media
        Route::post('/media/{mediaId}/comments', [KupunesiaChecklistController::class, 'addMediaComment']);
        Route::post('/media/{mediaId}/rating', [KupunesiaChecklistController::class, 'rateMedia']);
    });
});

// ============================================================================
// TAXA ROUTES
// ============================================================================

Route::prefix('taxa')->group(function () {
    // Universal Taxa Detail Routes
    Route::get('/validate', [TaxaDetailController::class, 'validateTaxa']);
    
    // Search by rank
    Route::get('/{rank}/search', [TaxaDetailController::class, 'search'])
        ->where('rank', 'domain|superkingdom|kingdom|subkingdom|superphylum|phylum|subphylum|superclass|class|subclass|infraclass|magnorder|superorder|order|suborder|infraorder|parvorder|superfamily|family|subfamily|supertribe|tribe|subtribe|genus|subgenus|species|subspecies|variety|form|subform');
    
    // Distribution
    Route::get('/{rank}/{id}/distribution', [TaxaDetailController::class, 'getDistribution'])
        ->where('rank', 'domain|superkingdom|kingdom|subkingdom|superphylum|phylum|subphylum|superclass|class|subclass|infraclass|magnorder|superorder|order|suborder|infraorder|parvorder|superfamily|family|subfamily|supertribe|tribe|subtribe|genus|subgenus|species|subspecies|variety|form|subform');
    
    // Similar taxa
    Route::get('/{rank}/{id}/similar', [TaxaDetailController::class, 'getSimilar'])
        ->where('rank', 'domain|superkingdom|kingdom|subkingdom|superphylum|phylum|subphylum|superclass|class|subclass|infraclass|magnorder|superorder|order|suborder|infraorder|parvorder|superfamily|family|subfamily|supertribe|tribe|subtribe|genus|subgenus|species|subspecies|variety|form|subform');
    
    // Conservation status
    Route::get('/{rank}/{id}/conservation-status', [TaxaDetailController::class, 'getConservationStatus'])
        ->where('rank', 'species|subspecies|variety|form');
    
    // Detail (must be last)
    Route::get('/{rank}/{id}', [TaxaDetailController::class, 'show'])
        ->where('rank', 'domain|superkingdom|kingdom|subkingdom|superphylum|phylum|subphylum|superclass|class|subclass|infraclass|magnorder|superorder|order|suborder|infraorder|parvorder|superfamily|family|subfamily|supertribe|tribe|subtribe|genus|subgenus|species|subspecies|variety|form|subform');
});

// Taksa search routes
Route::prefix('taksa')->group(function () {
    Route::get('/search', [TaxaController::class, 'search']);
    Route::get('/search/animalia', [TaxaController::class, 'searchAnimalia']);
    Route::get('/search/plantae', [TaxaController::class, 'searchPlantae']);
    Route::get('/search/fungi', [TaxaController::class, 'searchFungi']);
    Route::get('/search/kupunesia', [TaxaController::class, 'searchKupunesia']);
    Route::get('/search/burungnesia', [TaxaController::class, 'searchBurungnesia']);
    Route::get('/iucn-status', [TaxaController::class, 'getIUCNStatus']);
    Route::get('/detail/{id}', [TaxaController::class, 'detail']);
    Route::get('/download/sqlite', [TaxaController::class, 'generateSqliteDatabase']);
    Route::get('/all', [TaxaController::class, 'getAllTaxa']);
});

// Taxa Naming Flags
Route::prefix('taxa-flags')->group(function () {
    Route::get('/types', [TaxaNamingFlagController::class, 'getFlagTypes']);
    Route::get('/stats', [TaxaNamingFlagController::class, 'getStats']);
    Route::get('/taxa/{taxaId}', [TaxaNamingFlagController::class, 'getByTaxa']);
    
    Route::middleware('auth:api')->group(function () {
        Route::post('/', [TaxaNamingFlagController::class, 'store']);
    });
});

// ============================================================================
// GALLERY ROUTES
// ============================================================================

Route::prefix('gallery')->group(function () {
    // General gallery
    Route::get('/', [GalleryController::class, 'index']);
    
    // Species Gallery
    Route::prefix('species')->group(function () {
        Route::get('/', [SpeciesGalleryController::class, 'getSpeciesGallery']);
        Route::get('/detail/{taxaId}', [SpeciesGalleryController::class, 'getSpeciesDetail']);
        Route::get('/{taxaId}/similar', [SpeciesGalleryController::class, 'getSimilarSpecies']);
        Route::get('/{taxaId}/distribution', [SpeciesGalleryController::class, 'getSpeciesDistribution']);
    });
    
    // Genus Gallery
    Route::prefix('genus')->group(function () {
        Route::get('/', [GenusGalleryController::class, 'getGenusGallery']);
        Route::get('/detail/{taxaId}', [GenusGalleryController::class, 'getGenusDetail']);
        Route::get('/{taxaId}/similar', [GenusGalleryController::class, 'getSimilarGenera']);
        Route::get('/{taxaId}/distribution', [GenusGalleryController::class, 'getGenusDistribution']);
    });
    
    // Kingdom Gallery
    Route::prefix('kingdom')->group(function () {
        Route::get('/', [KingdomGalleryController::class, 'getKingdomGallery']);
        Route::get('/detail/{taxaId}', [KingdomGalleryController::class, 'getKingdomDetail']);
        Route::get('/{taxaId}/similar', [KingdomGalleryController::class, 'getSimilarKingdoms']);
        Route::get('/{taxaId}/distribution', [KingdomGalleryController::class, 'getKingdomDistribution']);
        Route::get('/{taxaId}/phyla', [KingdomGalleryController::class, 'getPhylaInKingdom']);
    });
});

// ============================================================================
// MAP & GRID ROUTES
// ============================================================================

Route::prefix('map')->group(function () {
    // Markers
    Route::get('/markers', [MarkerController::class, 'getMarkers']);
    Route::get('/markers/fobi', [FobiMarkerController::class, 'getMarkers']);
    Route::get('/markers/by-taxa', [MarkerController::class, 'getMarkersByTaxa']);
    Route::get('/markers/fobi/by-taxa', [FobiMarkerController::class, 'getMarkersByTaxa']);
    
    // Tiles
    Route::get('/tiles/{z}/{x}/{y}', [MarkerController::class, 'getTileData']);
    
    // Grid
    Route::get('/grid-cells', [GridCellController::class, 'findCells']);
    Route::get('/grid-species/{checklist_id}', [GridSpeciesController::class, 'getSpeciesInChecklist']);
    Route::get('/fobi-species/{checklist_id}/{source}', [FobiMarkerController::class, 'getSpeciesInChecklist']);
    
    // Polygon
    Route::post('/polygon-stats', [HomeController::class, 'getPolygonStats']);
    Route::post('/grids-in-polygon', [HomeController::class, 'getGridsInPolygon']);
    Route::get('/grid-data/{gridId}', [HomeController::class, 'getGridData']);
    Route::post('/grid-species-count', [HomeController::class, 'getGridSpeciesCount']);
    Route::post('/grid-contributors', [HomeController::class, 'getGridContributors']);
    Route::post('/observations-by-ids', [HomeController::class, 'getObservationsByIds']);
});

// Location Labels
Route::prefix('location-labels')->group(function () {
    Route::get('/search', [LocationLabelController::class, 'search']);
    Route::post('/', [LocationLabelController::class, 'store']);
});

// ============================================================================
// NOTIFICATION ROUTES
// ============================================================================

Route::prefix('notifications')->middleware('jwt.verify')->group(function () {
    Route::get('/', [NotificationController::class, 'index']);
    Route::post('/{id}/read', [NotificationController::class, 'markAsRead']);
    Route::post('/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::get('/unread-count', [NotificationController::class, 'getUnreadCount']);
});

// ============================================================================
// MESSAGE ROUTES
// ============================================================================

Route::prefix('messages')->middleware('auth:api')->group(function () {
    Route::get('/inbox', [MessageController::class, 'inbox']);
    Route::get('/unread-count', [MessageController::class, 'unreadCount']);
    Route::get('/conversation/{userId}', [MessageController::class, 'conversation']);
    Route::post('/send', [MessageController::class, 'send']);
    Route::post('/mark-read/{userId}', [MessageController::class, 'markAsRead']);
    Route::delete('/{messageId}', [MessageController::class, 'delete']);
});

// ============================================================================
// BADGE ROUTES
// ============================================================================

Route::prefix('badges')->group(function () {
    // Authenticated routes
    Route::middleware('auth:api')->group(function () {
        Route::get('/', [BadgeController::class, 'index']);
        Route::post('/', [BadgeController::class, 'store']);
        Route::get('/types', [BadgeController::class, 'getBadgeTypes']);
        Route::get('/statistics', [BadgeController::class, 'statistics']);
        Route::get('/app/{app}', [BadgeController::class, 'getByApplication']);
        Route::post('/app/{app}/progress', [BadgeController::class, 'getUserProgress']);
        Route::post('/app/{app}/check', [BadgeController::class, 'checkNewBadges']);
        Route::get('/{id}', [BadgeController::class, 'show']);
        Route::put('/{id}', [BadgeController::class, 'update']);
        Route::delete('/{id}', [BadgeController::class, 'destroy']);
    });
    
    // Public routes
    Route::prefix('public')->group(function () {
        Route::get('/', [BadgeController::class, 'index']);
        Route::get('/types', [BadgeController::class, 'getBadgeTypes']);
        Route::get('/app/akar', function (Request $request) {
            return app(BadgeController::class)->getByApplication($request, 'akar');
        });
        Route::get('/{id}', [BadgeController::class, 'show']);
    });
});

// ============================================================================
// MEDIA ROUTES
// ============================================================================

Route::prefix('media')->group(function () {
    // Auth Media (public)
    Route::get('/auth', [AuthMediaController::class, 'getAll']);
    Route::get('/auth/by-type', [AuthMediaController::class, 'getByType']);
    
    // Authenticated media routes
    Route::middleware('auth:api')->group(function () {
        Route::post('/', [FobiObservationApiController::class, 'storeMedia']);
        Route::put('/images/{id}', [FobiObservationApiController::class, 'updatePhoto']);
        Route::delete('/images/{id}', [FobiObservationApiController::class, 'deletePhoto']);
        Route::put('/{mediaId}/license', [ChecklistObservationController::class, 'updateMediaLicense']);
        
        // Media comments & ratings
        Route::get('/{mediaId}/comments', [ChecklistDetailController::class, 'getMediaComments']);
        Route::post('/{mediaId}/comments', [ChecklistDetailController::class, 'addMediaComment']);
        Route::get('/{mediaId}/rating', [ChecklistDetailController::class, 'getMediaRating']);
        Route::post('/{mediaId}/rating', [ChecklistDetailController::class, 'rateMedia']);
    });
});

// ============================================================================
// HISTORY ROUTES
// ============================================================================

Route::prefix('history')->middleware('auth:api')->group(function () {
    // User history
    Route::get('/identifications', [HistoryController::class, 'getUserIdentificationHistory']);
    Route::get('/flags', [HistoryController::class, 'getUserFlags']);
    
    // Admin history
    Route::prefix('admin')->middleware('checkRole:3,4')->group(function () {
        Route::get('/identifications', [HistoryController::class, 'getAllIdentificationHistory']);
        Route::get('/flags', [HistoryController::class, 'getAllFlags']);
    });
    
    // Observation history
    Route::get('/observations/{id}/identifications', [HistoryController::class, 'getChecklistIdentificationHistory']);
    Route::get('/observations/{id}/flags', [HistoryController::class, 'getChecklistFlags']);
});

// ============================================================================
// USER REPORT ROUTES
// ============================================================================

Route::prefix('user-reports')->middleware('auth:api')->group(function () {
    Route::post('/', [UserReportController::class, 'store']);
    Route::get('/check/{userId}', [UserReportController::class, 'checkReport']);
});

// ============================================================================
// GENERAL ROUTES
// ============================================================================

// Home & Statistics
Route::prefix('home')->group(function () {
    Route::get('/order-faunas', [HomeController::class, 'getOrderFaunas']);
    Route::get('/checklists', [HomeController::class, 'getChecklists']);
    Route::get('/families', [HomeController::class, 'getFamilies']);
    Route::get('/ordos', [HomeController::class, 'getOrdos']);
    Route::get('/faunas', [HomeController::class, 'getFaunas']);
    Route::get('/filtered-stats', [HomeController::class, 'getFilteredStats']);
    
    // Counts
    Route::get('/burungnesia-count', [HomeController::class, 'getBurungnesiaCount']);
    Route::get('/kupunesia-count', [HomeController::class, 'getKupunesiaCount']);
    Route::get('/fobi-count', [HomeController::class, 'getFobiCount']);
    Route::get('/total-species', [HomeController::class, 'getTotalSpecies']);
    Route::get('/total-contributors', [HomeController::class, 'getTotalContributors']);
    
    // User counts
    Route::get('/user-burungnesia-count/{userId}', [HomeController::class, 'getUserBurungnesiaCount']);
    Route::get('/user-kupunesia-count/{userId}', [HomeController::class, 'getUserKupunesiaCount']);
    Route::get('/user-total-observations/{userId}', [HomeController::class, 'getUserTotalObservations']);
});

// Search
Route::get('/search', [SearchController::class, 'search']);
Route::get('/search-users', [HomeController::class, 'searchUsers']);
Route::get('/search-locations', [HomeController::class, 'searchLocations']);

// Species suggestions
Route::get('/species-suggestions', [SpeciesSuggestionController::class, 'suggest']);
Route::get('/faunas/search', [SpeciesSearchController::class, 'search']);

// Taxonomy search (authenticated)
Route::middleware('auth:api')->group(function () {
    Route::get('/taxonomy/search', [ChecklistObservationController::class, 'searchTaxa']);
    Route::get('/taxonomy/birds/search', [ChecklistObservationController::class, 'searchBirdTaxa']);
    Route::get('/taxonomy/butterflies/search', [ChecklistObservationController::class, 'searchButterflyTaxa']);
    Route::get('/taxonomy', [ChecklistObservationController::class, 'getTaxonomy']);
    Route::get('/burnes/birds/search', [BirdIdentificationController::class, 'searchBirds']);
});

// Page Content
Route::get('/pages', [PageContentController::class, 'index']);
Route::get('/pages/{slug}', [PageContentController::class, 'show']);

// Quality Assessment
Route::middleware('auth:api')->group(function () {
    Route::apiResource('quality-assessments', QualityAssessmentController::class);
    Route::post('quality-assessments/identify', [QualityAssessmentController::class, 'addIdentification']);
    Route::post('quality-assessments/verify-location', [QualityAssessmentController::class, 'verifyLocation']);
    Route::post('quality-assessments/vote-wild-status', [QualityAssessmentController::class, 'voteWildStatus']);
    Route::post('quality-assessments/verify-evidence', [QualityAssessmentController::class, 'verifyEvidence']);
    Route::post('quality-assessment/reprocess-implicit-agreements', [ChecklistQualityAssessmentController::class, 'reprocessImplicitAgreements']);
    Route::post('quality-assessment/reprocess-implicit-agreements/{checklistId}', [ChecklistQualityAssessmentController::class, 'reprocessImplicitAgreements']);
});

// Checklist Taxa
Route::middleware('jwt.verify')->group(function () {
    Route::post('/fobi-checklist-taxas', [FobiChecklistTaxaController::class, 'store']);
});

// Synonym fallback
Route::post('observations/process-synonym-fallback', [FobiGeneralObservationController::class, 'processWithSynonymFallback'])->middleware('auth:api');

// Upload session
Route::get('generate-upload-session', [FobiGeneralObservationController::class, 'generateUploadSession']);

// IUCN Status
Route::get('/observations/iucn-status', [ChecklistObservationController::class, 'getIUCNStatus']);
