<?php

use Illuminate\Http\Request;
use App\Marketing;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});


// --- Register

Route::post('register', 'Auth\RegisterController@register');

// --- Login
Route::post('login', 'Auth\LoginController@login');
Route::post('token', 'Auth\LoginController@token'); // allows login with token

// --- Password Reset
Route::post('passwordReset', 'Auth\ForgotPasswordController@passwordReset');

// --- Logout

Route::post('logout', 'Auth\LoginController@logout');

// --- Contact Preferences
Route::post('marketing', 'Dashboard\MarketingController@marketing')->middleware('auth:api');
Route::post('preferences', 'Dashboard\MarketingController@preferences');


Route::prefix('user')->group(
    function () {
        Route::post(
            'register',
            'UserController@register'
        );
        Route::post(
            'login',
            'UserController@login'
        );
        Route::post(
            'token',
            'UserController@token'
        );
    }
);
Route::get(
    'communication/{contractId}/{ordinal}/{packId}/{communicationId}/{communicationType}',
    'DataController@communication'
)->middleware('auth:api');

Route::post(
    'updateContactDetailsAndMarketingPreferences',
    'DataController@updateContactAndMarketingDetails'
)->middleware('auth:api');

Route::post(
    'updateAddress',
    'DataController@updateAddress'
)->middleware('auth:api');

Route::post(
    'updateaccount',
    'DataController@updateAccountInfo'
    )->middleware('auth:api');


Route::get(
    'data',
    'DataController@get'
)->middleware('auth:api');

Route::get(
    'document/{policyId}/{hashedFileUrl}/{documentName}',
    'DataController@document'
)->middleware('auth:api');

Route::get(
    'tobaDocument/{toba}/{policyId}',
    'DataController@tobaDocument'
)->middleware('auth:api');

Route::post(
    'mtaQuote',
    'DataController@getMTAQuote'
)->middleware('auth:api');

Route::post(
    'postZeroCostMTA',
    'DataController@postZeroCostMTA'
)->middleware('auth:api');

Route::get(
    'electraMtaQuote',
    'DataController@getMTAQuoteElectra'
);

Route::post(
    'postMTA',
    'DataController@postMTA'
)->middleware('auth:api');

Route::post(
    'postCancellation',
    'DataController@postCancellation'
)->middleware('auth:api');

Route::post(
    'regVehicleLookups',
    'DataController@getRegVehicleLookups'
)->middleware('auth:api');

Route::post('paymentHubSetup', 'PaymentController@paymentHubSetup')->middleware('auth:api');

Route::get(
    'addressLookup',
    'DataController@addressLookup'
);

Route::prefix('lookup')->group(
    function() {
        Route::get(
            '{listName}',
            'DataController@getLookupList'
        );
        Route::get(
            'vehicle-models-by-make/{makeCode}',
            'DataController@getVehicleModelsByMake'
        );
    }
);

Route::post('postReturnPremiumMTA', 'DataController@postReturnPremiumMTA')->middleware('auth:api');

Route::group(['middleware' => 'client'], function() {
    Route::post('client/processPayment', 'PaymentController@processPayment');
    Route::get(
        'client/resubmitMTA/{transactionId}',
        'DataController@resubmitMTA'
    );
    Route::post(
        'electraMtaQuoteAPI',
        'DataController@getMTAQuoteElectraAPI'
    );
});

Route::post(
    'save-policy-cache',
    'DataController@savePolicyCache'
)->middleware('auth:api');

Route::post(
    'linkPolicies',
    'DataController@linkPolicies'
    )->middleware('auth:api');

Route::post('policyCancellationApi', 'DataController@policyCancellation')->middleware('auth:api');
