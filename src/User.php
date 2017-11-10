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

use Rhubarb\Crown\DateTime\RhubarbDateTime;
use Rhubarb\Crown\Encryption\HashProvider;
use Rhubarb\Crown\LoginProviders\Exceptions\NotLoggedInException;
use Rhubarb\Crown\LoginProviders\LoginProvider;
use Rhubarb\Scaffolds\Authentication\Exceptions\TokenException;
use Rhubarb\Scaffolds\Authentication\Settings\AuthenticationSettings;
use Rhubarb\Stem\Exceptions\ModelConsistencyValidationException;
use Rhubarb\Stem\Exceptions\RecordNotFoundException;
use Rhubarb\Stem\Filters\AndGroup;
use Rhubarb\Stem\Filters\Equals;
use Rhubarb\Stem\Filters\GreaterThan;
use Rhubarb\Stem\Filters\Not;
use Rhubarb\Stem\Interfaces\ValidateLoginModelInterface;
use Rhubarb\Stem\Models\Model;
use Rhubarb\Stem\Schema\Columns\AutoIncrementColumn;
use Rhubarb\Stem\Schema\Columns\BooleanColumn;
use Rhubarb\Stem\Schema\Columns\DateTimeColumn;
use Rhubarb\Stem\Schema\Columns\StringColumn;
use Rhubarb\Stem\Schema\ModelSchema;

class User extends Model implements ValidateLoginModelInterface
{
    /**
     * This flag is used to check whether a password has been changed and needs to be validated
     * inside getConsistencyValidationErrors()
     * @var bool
     */
    private $passwordChanged = false;

    /**
     * Returns the schema for this data object.
     *
     * @return \Rhubarb\Stem\Schema\ModelSchema
     */
    protected function createSchema()
    {
        $schema = new ModelSchema("tblAuthenticationUser");

        $schema->addColumn(
            new AutoIncrementColumn("UserID"),
            new StringColumn("Username", 30, null),
            new StringColumn("Password", 200),
            new StringColumn("Forename", 80),
            new StringColumn("Surname", 80),
            new StringColumn("Email", 150),
            new StringColumn("Token", 200),
            new DateTimeColumn("TokenExpiry"),
            new BooleanColumn("Enabled", false),
            new StringColumn("PasswordResetHash", 200),
            new DateTimeColumn("PasswordResetDate"),
            new DateTimeColumn("LastPasswordChangeDate")
        );

        $schema->labelColumnName = "FullName";

        return $schema;
    }

    public function getFullName()
    {
        return trim($this->Forename . " " . $this->Surname);
    }

    protected function setDefaultValues()
    {
        parent::setDefaultValues();

        // New users should be enabled by default.
        $this->Enabled = true;
    }

    /**
     * Creates and returns a reset password hash that can be emailed to the user to invite them to reset their password.
     */
    public function generatePasswordResetHash()
    {
        $hashProvider = HashProvider::getProvider();
        $hash = sha1($hashProvider->createHash($this->UserID . uniqid(), uniqid("salt")));

        $this->PasswordResetHash = $hash;
        $this->PasswordResetDate = "now";
        $this->save();

        return $hash;
    }

    public function setNewPassword($password)
    {
        $provider = HashProvider::getProvider();

        $this->Password = $provider->createHash($password);
        $this->PasswordResetHash = "";

        $this->LastPasswordChangeDate = new RhubarbDateTime('now');

        $this->passwordChanged = true;
    }

    /**
     * Returns a user with a matching password reset hash.
     *
     * @param $hash
     * @return User
     */
    public static function fromPasswordResetHash($hash)
    {
        return self::findFirst(new Equals("PasswordResetHash", $hash));
    }

    /**
     * @param $username
     * @return User
     * @throws \Rhubarb\Stem\Exceptions\RecordNotFoundException
     * @deprecated
     */
    public static function fromUsername($username)
    {
        return self::fromIdentifierColumnValue($username);
    }

    /**
     * @param mixed $value
     * @return Model|static
     */
    public static function fromIdentifierColumnValue($value)
    {
        $settings = AuthenticationSettings::singleton();
        return self::findFirst(new Equals($settings->identityColumnName, $value));
    }

    /**
     * Returns the logged in User model
     *
     * @throws NotLoggedInException
     */
    public static function getLoggedInUser()
    {
        $loginProvider = LoginProvider::getProvider();

        return $loginProvider->getModel();
    }

    /**
     * Returns a unique StringColumn identifying this record in the user table.
     *
     * @throws Exceptions\TokenException
     * @return StringColumn
     */
    private function getSavedPasswordTokenData()
    {
        if ($this->isNewRecord()) {
            // We can't fulfil the request as we have no UserID which is required for the StringColumn.
            throw new TokenException("The user has not been saved");
        }

        return sha1($this->Username . $this->Password . $this->FullName . $this->Enabled . $this->UserID);
    }

    /**
     * Creates a token for the user which allows for logging in via a cookie.
     *
     * @throws Exceptions\TokenException
     * @return StringColumn The token.
     */
    public function createToken()
    {
        $hashProvider = HashProvider::getProvider();
        $token = $hashProvider->createHash($this->getSavedPasswordTokenData(), sha1($this->Password));

        $this->Token = $token;
        $this->TokenExpiry = date("Y-m-d H:i:s", strtotime("+2 weeks"));
        $this->save();

        return $token;
    }

    protected function getConsistencyValidationErrors()
    {
        $errors = parent::getConsistencyValidationErrors();

        if ($this->Enabled) {
            $settings = AuthenticationSettings::singleton();
            $identityColumnName = $settings->identityColumnName;

            // See if the identity is in use.
            $identityFilter = new Equals($identityColumnName, $this->$identityColumnName);
            if (!$this->isNewRecord()) {
                $identityFilter = new AndGroup([
                    $identityFilter,
                    new Not(new Equals($this->getUniqueIdentifierColumnName(), $this->getUniqueIdentifier()))
                ]);
            }
            try
            {
                self::findFirst($identityFilter);
                $errors[$identityColumnName] = "This ".$identityColumnName." is already in use";
            }
            catch(RecordNotFoundException $ex) {
                // all is well!
            }

            if (!$this->$identityColumnName) {
                $errors[$identityColumnName] = "The user must have a ".$identityColumnName;
            }

            if ($this->FullName == "") {
                $errors["Name"] = "The user must have a name";
            }
        }

        //  Validate new password has not been previously used
        $numberOfPastPasswordsToCompareTo = AuthenticationSettings::singleton()->numberOfPastPasswordsToCompareTo;
        if ($this->passwordChanged && $numberOfPastPasswordsToCompareTo) {
            $hashProvider = HashProvider::getProvider();

            $userPastPasswords = UserPastPassword::find(new Equals($this->UniqueIdentifierColumnName, $this->UniqueIdentifier));
            $userPastPasswords->addSort("DateCreated", false);
            $userPastPasswords->setRange(0, $numberOfPastPasswordsToCompareTo);

            foreach ($userPastPasswords as $userPastPassword) {
                if ($hashProvider->compareHash($this->Password, $userPastPassword->Password)) {
                    $errors["Password"] = "The password you have entered has already been used. Please enter a new password.";
                    break;
                }
            }
        }

        return $errors;
    }

    /**
     * Checks that the token supplied is valid for this user.
     *
     * @param $token
     * @return bool
     */
    public function validateToken($token)
    {
        // The token must match of course.
        if ($this->Token != $token) {
            return false;
        }

        // Has the token expired?
        if (strtotime($this->TokenExpiry) < time()) {
            return false;
        }

        $hashProvider = HashProvider::getProvider();
        return $hashProvider->compareHash($this->getSavedPasswordTokenData(), $token);
    }

    protected function attachPropertyChangedNotificationHandlers()
    {
        parent::attachPropertyChangedNotificationHandlers();

        if (AuthenticationSettings::singleton()->storeUserPasswordChanges) {
            $this->addPropertyChangedNotificationHandler('Password', function ($newValue, $propertyName, $oldValue) {
                $this->performAfterSave(
                    function () use ($propertyName, $oldValue) {
                        if ($propertyName == "Password" && !empty($oldValue) && $this->Password != $oldValue) {
                            UserPastPassword::removePreviousPasswords($this->UniqueIdentifier);
                            $userPastPassword = new UserPastPassword();
                            $userPastPassword->UserID = $this->UniqueIdentifier;
                            $userPastPassword->Password = $oldValue;
                            $userPastPassword->save();
                        }
                    }
                );
            });
        }
    }

    public function isModelExpired()
    {
        $passwordExpirationDaysInterval = AuthenticationSettings::singleton()->passwordExpirationIntervalInDays;

        /** @var $lastPasswordChangeDate \Rhubarb\Crown\DateTime\RhubarbDateTime */
        $lastPasswordChangeDate = $this->LastPasswordChangeDate;
        $currentDate = new RhubarbDateTime('now');

        if ($passwordExpirationDaysInterval && $lastPasswordChangeDate && $lastPasswordChangeDate->isValidDateTime()) {
            $timeDifference = $currentDate->diff($lastPasswordChangeDate);
            if ($timeDifference->totalDays > $passwordExpirationDaysInterval) {
                return true;
            }
        }

        return false;
    }

    public function isModelDisabled()
    {
        if (!AuthenticationSettings::singleton()->disableAccountAfterFailedLoginAttempts) {
            return false;
        }

        $andGroupFilter = new AndGroup();
        $andGroupFilter->addFilters(new Equals("EnteredUsername", $this->Username));
        $andGroupFilter->addFilters(new Equals("Successful", false));

        // Retrieve last successful login attempt
        $lastSuccesfulLoginAttempt = UserLoginAttempt::getLastSuccessfulLoginAttempt($this->Username);
        if ($lastSuccesfulLoginAttempt) {
            $andGroupFilter->addFilters(new GreaterThan("UserLoginAttemptID", $lastSuccesfulLoginAttempt->UserLoginAttemptID));
        }

        //  Get all failed login attempts from the last successful login if one can be found
        $failedUserLoginAttempts = UserLoginAttempt::find($andGroupFilter);
        $failedUserLoginAttempts->addSort("DateModified", false);

        if ($failedUserLoginAttempts->count() >= AuthenticationSettings::singleton()->numberOfFailedLoginAttemptsThreshold) {
            $currentDate = new RhubarbDateTime('now');

            //  Check if the most recent Failed Login attempt was within the $totalMinutesToDisableUserAccount set within the AuthenticationSettings
            $mostRecentFailedLoginAttempt = $failedUserLoginAttempts[0];

            $timeDifference = $currentDate->diff($mostRecentFailedLoginAttempt->DateModified);
            if ($timeDifference->totalMinutes < AuthenticationSettings::singleton()->totalMinutesToDisableUserAccount) {
                return false;
            } else {
                return true;
            }
        }

        return false;
    }
}
