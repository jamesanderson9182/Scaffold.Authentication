<?php

/*
 *	Copyright 2015 RhubarbPHP
 *
 *  Licensed under the Apache License, Version 2.0 (the "License");
 *  you may not use this file except in compliance with the License.
 *  You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 *  Unless required by applicable law or agreed to in writing, software
 *  distributed under the License is distributed on an "AS IS" BASIS,
 *  WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 *  See the License for the specific language governing permissions and
 *  limitations under the License.
 */

namespace Rhubarb\Scaffolds\Authentication;

use Rhubarb\Crown\LoginProviders\LoginProvider;
use Rhubarb\Crown\LoginProviders\UrlHandlers\ValidateLoginUrlHandler;
use Rhubarb\Crown\Module;
use Rhubarb\Leaf\UrlHandlers\LeafCollectionUrlHandler;
use Rhubarb\Scaffolds\Authentication\Settings\AuthenticationSettings;
use Rhubarb\Scaffolds\Authentication\Settings\ProtectedUrl;
use Rhubarb\Scaffolds\Authentication\UrlHandlers\CallableUrlHandler;
use Rhubarb\Stem\Schema\SolutionSchema;
use Rhubarb\Stem\StemModule;

class AuthenticationModule extends Module
{
    /**
     * Creates an instance of the Authentication module.
     *
     * @param null $loginProviderClassName
     * @param string $urlToProtect Optional. The URL stub to protect by requiring a login. Defaults to
     *                                  the entire URL tree.
     * @param string $loginUrl The URL to redirect the user to for logging in
     * @param bool $enablePasswordChangeLog Store recent password changes
     * @internal param string $identityColumnName The name of the column in the user table storing the login identity.
     */
    public function __construct($loginProviderClassName = null, $urlToProtect = '/', $loginUrl = '/login/', $enablePasswordChangeLog = false)
    {
        parent::__construct();

        if ($loginProviderClassName != null) {
            LoginProvider::setProviderClassName($loginProviderClassName);
          }

        if ($loginProviderClassName !== null) {
            $this->registerProtectedUrl(new ProtectedUrl(
                $urlToProtect,
                $loginProviderClassName,
                $loginUrl
            ));
        }

        AuthenticationSettings::singleton()->storeUserPasswordChanges = $enablePasswordChangeLog;
    }

    public function registerProtectedUrl(ProtectedUrl $urlToProtect)
    {
        $this->protectedUrls[] = $urlToProtect;
    }

    /** @var ProtectedUrl[] */
    private $protectedUrls = [];

    public function initialise()
    {
        SolutionSchema::registerSchema('Authentication', DatabaseSchema::class);
    }

    protected function registerUrlHandlers()
    {
        foreach ($this->protectedUrls as $url) {

            $provider = $url->loginProviderClassName;

            $this->addUrlHandlers([
                $url->loginUrl => $login = new CallableUrlHandler(function () use ($url) {
                    $className = $url->loginLeafClassName;
                    return new $className($url->loginProviderClassName);
                }, [
                    $url->resetChildUrl => $reset = new LeafCollectionUrlHandler(
                        $url->resetPasswordLeafClassName,
                        $url->confirmResetPasswordLeafClassName
                    ),
                    $url->logoutChildUrl => $logout = new CallableUrlHandler(function () use ($url) {
                        $className = $url->logoutLeafClassName;
                        return new $className($url->loginProviderClassName);
                    }),
                ]),
                $url->urlToProtect => $protected =
                    new ValidateLoginUrlHandler($provider::singleton(), $url->loginUrl),
            ]);

            // Make sure that the login url handlers are given greater precedence than those of the application.
            $login->setPriority(10);
            $login->setName('login');

            $logout->setPriority(10);
            $logout->setName('logout');

            $reset->setPriority(10);
            $reset->setName('reset');

            $protected->setPriority(10);
        }
    }

    /**
     * Should your module require other modules, they should register the module here.
     */
    protected function getModules()
    {
        return [new StemModule()];
    }
}
