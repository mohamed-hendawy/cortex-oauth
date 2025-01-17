<?php

declare(strict_types=1);

Route::domain(domain())->group(function () {
    Route::name('frontarea.')
         ->middleware(['web', 'auth'])
         ->namespace('Cortex\OAuth\Http\Controllers\Frontarea')
         ->prefix(config('cortex.foundation.route.locale_prefix') ? '{locale}/'.config('cortex.foundation.route.prefix.frontarea') : config('cortex.foundation.route.prefix.frontarea'))->group(function () {

        // Register OAuth Routes
             Route::name('cortex.oauth.')->group(function () {

            // Authorization process
                 Route::prefix('oauth')->group(function () {
                     Route::get('authorize')->name('authorizations.authorize')->uses('AuthorizationController@authorizeRequest');
                     Route::post('authorize')->name('authorizations.approve')->uses('AuthorizationController@approve');
                     Route::delete('authorize')->name('authorizations.deny')->uses('AuthorizationController@deny');
                     Route::post('token')->name('authorizations.token')->uses('AuthorizationController@issueToken');
                     Route::post('token/refresh')->name('authorizations.token.refresh')->uses('AuthorizationController@refreshToken');
                 });

                 // Managing clients, auth codes, and access tokens
                 Route::match(['get', 'post'], 'clients')->name('clients.index')->uses('ClientsController@index');
                 Route::get('clients/create')->name('clients.create')->uses('ClientsController@create');
                 Route::post('clients/create')->name('clients.store')->uses('ClientsController@store');
                 Route::get('clients/{client}/edit')->name('clients.edit')->uses('ClientsController@edit');
                 Route::put('clients/{client}/edit')->name('clients.update')->uses('ClientsController@update');
                 Route::put('clients/{client}')->name('clients.revoke')->uses('ClientsController@revoke');
                 Route::delete('clients/{client}')->name('clients.destroy')->uses('ClientsController@destroy');
                 Route::match(['get', 'post'], 'clients/{client}/auth-codes')->name('clients.auth_codes')->uses('ClientsController@authCodes');
                 Route::match(['get', 'post'], 'clients/{client}/access-tokens')->name('clients.access_tokens')->uses('ClientsController@accessTokens');
             });
         });
});
