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

namespace Rhubarb\Scaffolds\Authentication\Presenters;

use Rhubarb\Leaf\Presenters\Controls\Buttons\Button;
use Rhubarb\Leaf\Presenters\Controls\Text\Password\Password;
use Rhubarb\Leaf\Views\HtmlView;
use Rhubarb\Leaf\Views\MessageViewTrait;
use string;

class ConfirmResetPasswordView extends HtmlView
{
    use MessageViewTrait;

    public function createPresenters()
    {
        parent::createPresenters();

        $this->addPresenters(
            new Password("NewPassword"),
            new Password("ConfirmNewPassword"),
            new Button("ResetPassword", "Reset Password", function () {
                $this->raiseEvent("ConfirmPasswordReset");
            })
        );
    }

    protected function printViewContent()
    {
        $this->printFieldset("Resetting your password",
            "<p>Complete your password reset by entering a new password.</p>",
            [
                "Enter new password" => "NewPassword",
                "Enter again to confirm" => "ConfirmNewPassword",
                "" => "ResetPassword"
            ]
        );
    }

    /**
     * Should return an array of key value pairs storing message texts against an arbitrary tracking code.
     *
     * @return string[]
     */
    protected function getMessages()
    {
        return
            [
                "PasswordReset" => <<<PasswordReset
<p>Thanks, your password has now been reset. If you still have difficulties logging in you
should contact us for assistance. We will never ask you for your password, but we should
be able to reset it for you.</p>
PasswordReset
            ];
    }
}