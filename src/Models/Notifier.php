<?php

namespace NSWDPC\Authentication;

use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Config\Config;
use SilverStripe\Security\Member;
use SilverStripe\Security\Group;
use SilverStripe\Security\Permission;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\SiteConfig\SiteConfig;
use SilverStripe\View\ArrayData;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\FieldType\DBVarchar;
use SilverStripe\Control\Email\Email;
use Silverstripe\Control\Controller;
use SilverStripe\MFA\Extension\MemberExtension as MFAMemberExtension;
use SilverStripe\Security\Security;

/**
 * Notification model for nswpdc/silverstripe-members
 * @author James <james.ellis@dpc.nsw.gov.au>
 */
class Notifier {

    use Configurable;
    use Injectable;

    private static $default_email_from = "";

    /**
     * Returns the default email from to be used in email sends
     * Can be a string "bob@example.com" or an array ["bob@example" => "Bob McBobFace"]
     */
    public function getDefaultFrom() {
        $default_email_from = $this->config()->get('default_email_from');
        if(!$default_email_from) {
            $default_email_from = Config::inst()->get(Email::class, 'admin_email');
        }
        return $default_email_from;
    }

    /**
     * Sends profile update notification to a given account or accounts
     * @param Member $member the member that was updated
     * @param array $what an array of what has updated, each value is a human readable sentence
     * @param Member $to_member the member to notify (could be $member)
     * @param Group $to_group the group to notify
     */
    public function sendChangeNotification(Member $member, ArrayList $what, Member $to_member = null, Group $to_group = null)
    {
        $config = SiteConfig::current_site_config();
        $link = "";
        $content = ArrayData::create([
            'Member' => $member,
            'SiteConfig' => $config,
            'What' => $what,
            'ProfileChangeAlertLink' => $link
        ])->renderWith('NSWDPC/Authentication/Email/ProfileChangeNotification');

        $data = [];
        $data['Content'] = $content;

        // get the recipient
        $to = [];
        if($to_member) {
            $to[ $to_member->Email ] = $to_member->getName();
        }

        // send out group emails in a Bcc to stop emails being seen within the group
        // and are not visible to the to_member
        $headers = [];
        if($to_group) {
            $members = $group->Members();
            foreach($group_members as $group_member) {
                    $headers['Bcc'][ $group_member->Email ] = $group_member->getName();
            }
        }
        // send the notification
        return $this->sendEmail(
            $to,
            $this->getDefaultFrom(),
            _t(Configuration::class . ".PROFILE_CHANGED", "Your profile was updated" ),
            $data,
            $headers
        );
    }

    /**
     * Send an email containing a message and a link to complete the registration
     * @param Member $member
     * @param boolean $initial if false, this is a re-notification of registration approval (e.g a reprompt)
     * @param Controller $controller a controller that can provide a link to a URL where  the user can enter the code
     */
    public function sendSelfRegistrationToken(Member $member, $initial = false, Controller $controller) {
        if(!$controller->hasMethod('RegisterPendingLink')) {
            throw new \Exception("Failed: the controller does not provide RegisterPendingLink");
        }
        // current site config
        $config = SiteConfig::current_site_config();
        // link to registration completion
        $link = $controller->RegisterPendingLink();
        // ensure the user is marked pending with the latest configuration values
        $profile = $member->makePending($initial);
        // create a new approval code
        $code = $profile->createApprovalCode();
        // template data
        $content = ArrayData::create([
            'RequireAdminApproval' => $profile->RequireAdminApproval,
            'Code' => $code,
            'Initial' => $initial,
            'Member' => $member,
            'SiteConfig' => $config,
            'RegistrationCompletionLink' => $link
        ])->renderWith('NSWDPC/Authentication/Email/SendRegistrationToken');
        $data = [];
        $data['Content'] = $content;
        $to = [];
        $to[ $member->Email ] = $member->getName();
        $subject =  _t(
            Configuration::class . ".REGISTRATION_ACTION_REQUIRED",
            "Please verify your registration at {siteName}",
            [
                'siteName' => $config->Title
            ]
        );
        return $this->sendEmail(
            $to,
            $this->getDefaultFrom(),
            $subject,
            $data
        );
    }

    public function sendAdministrationApprovalRequired(PendingProfile $profile) {

        $notifications = 0;

        // current site config
        $config = SiteConfig::current_site_config();

        // member
        $member = $profile->Member();

        // approvers - based on permission
        $approvers = PendingProfile::getApprovers();
        if(!$approvers || $approvers->count() == 0) {
            Logger::log("Cannot sendAdministrationApprovalRequired as there are no approvers. Please create some with the 'Edit Pending Profile' permission.", "NOTICE");
            return false;
        }

        foreach($approvers as $approver) {

            // template data
            $content = ArrayData::create([
                'Approver' => $approver,
                'Member' => $member,
                'SiteConfig' => $config,
                'ApprovePendingProfileLink' => $profile->CMSEditLink()
            ])->renderWith('NSWDPC/Authentication/Email/NotifyApprovers');

            $data = [];
            $data['Content'] = $content;

            // TO: the approver
            $to = [];
            $to[ $approver->Email ] = $approver->getName();
            $subject = sprintf( _t(Configuration::class . ".APPROVAL_OF_ACCOUNT", "An account requires approval on %s"), $config->Title );

            $notification = $this->sendEmail(
                $to,
                $this->getDefaultFrom(),
                $subject,
                $data
            );
            $notifications++;

        }
        return $notifications;

    }

    /**
     * Notify a profile that they were approved
     */
    public function sendProfileApproved(PendingProfile $profile) {

        // current site config
        $config = SiteConfig::current_site_config();

        // member
        $member = $profile->Member();

        // template data
        $content = ArrayData::create([
            'Member' => $member,
            'MemberProfileSignInLink' => Security::login_url(),
            'SiteConfig' => $config
        ])->renderWith('NSWDPC/Authentication/Email/ApprovedByAdministrator');

        $data = [];
        $data['Content'] = $content;

        // TO: the approver
        $to = [];
        $to[ $member->Email ] = $member->getName();
        $subject = sprintf( _t(Configuration::class . ".ACCOUNT_APPROVED_SUBJECT", "Your account on %s was approved"), $config->Title );

        return $this->sendEmail(
            $to,
            $this->getDefaultFrom(),
            $subject,
            $data
        );

    }

    /**
     * Sends the email
     * @returns boolean
     * @param mixed $to either a string or array
     * @param mixed $from either a string or array
     * @param string $subject
     * @param array $data
     * @param array $headers extra Email headers e.g Cc, Bcc, X-Some-Header
     * @param string $template template to use
     */
    protected function sendEmail($to, $from, $subject, $data = [], $headers = [], $template = "NSWDPC/Authentication/Email") {
        $email = Email::create()
                    ->setFrom( $from )
                    ->setTo( $to )
                    ->setSubject( $subject )
                    ->setHTMLTemplate( $template )
                    ->setData( ArrayData::create( $data ) );
        if(!empty($headers['Cc'])) {
            $email->setCc($headers['Cc']);
            unset($headers['Cc']);
        }
        if(!empty($headers['Bcc'])) {
            $email->setCc($headers['Bcc']);
            unset($headers['Bcc']);
        }
        if(!empty($headers['Reply-To'])) {
            $email->setReplyTo($headers['Reply-To']);
            unset($headers['Reply-To']);
        }
        if(!empty($headers)) {
            foreach($headers as $header => $value) {
                $email->getSwiftMessage()->getHeaders()->addTextHeader($header, $value);
            }
        }
        return $email->send();
    }

    /**
     * Notify MFA administrators that a member reset their account via the reset account process
     * See {@link NSWDPC\Authentication\ResetAccountExtension}
     * Note: this requires a group with the relevant permission to be created, and members assigned
     * @param Member the member that reset their account
     * @param string $state started or completed
     */
    public function sendMfaAccountResetNotification(Member $resettingMember, string $state = 'completed') : bool {

        $permissionCode = MFAMemberExtension::MFA_ADMINISTER_REGISTERED_METHODS;

        $recipients = Permission::get_members_by_permission($permissionCode );
        if(!$recipients || $recipients->count() == 0) {
            Logger::log("sendMfaAccountResetNotification failed - no members can be notified", "NOTICE");
            return false;
        }

        // current site config
        $config = SiteConfig::current_site_config();

        // environment
        $browser = "";
        if(!empty($_SERVER['HTTP_USER_AGENT'])) {
            $browser = DBField::create_field(
                DBVarchar::class,
                $_SERVER['HTTP_USER_AGENT']
            );
        }

        $request = null;
        $requestIP = '';
        $controller = (Controller::has_curr() ? Controller::curr() : null);
        if($controller) {
            $request = $controller->getRequest();
            $requestIP = DBField::create_field(
                DBVarchar::class,
                $request->getIP()
            );
        }

        if($state == 'started') {
            $subject = _t(
                Configuration::class . ".ACCOUNT_RESET_MFA_STARTED",
                "An account reset was started on {siteTitle}",
                [
                    'siteTitle' => $config->Title
                ]
            );
        } else {
            // default completed
            $subject = _t(
                Configuration::class . ".ACCOUNT_WAS_RESET_MFA",
                "An account reset was completed on {siteTitle}",
                [
                    'siteTitle' => $config->Title
                ]
            );
        }

        $sends = 0;
        foreach($recipients as $recipient) {

            // template data
            $content = ArrayData::create([
                'RequestState' => $state,
                'Recipient' => $recipient,
                'ResettingMember' => $resettingMember,
                'Browser' => $browser,
                'RequestIP' => $requestIP,
                'SiteConfig' => $config
            ])->renderWith('NSWDPC/MFA/Email/MemberResetAccountNotification');

            $data = [];
            $data['Content'] = $content;

            try {
                $to = [];
                $to[ $recipient->Email ] = $recipient->getName();
                if($this->sendEmail(
                    $to,
                    $this->getDefaultFrom(),
                    $subject,
                    $data
                )) {
                    $sends++;
                }
            } catch (\Exception $e) {
                // failed to notify
                Logger::log("sendMfaAccountResetNotification failed for member #{$recipient->ID}", "NOTICE");
            }
        }
        return $sends > 0;
    }

    /**
     * Notify MFA administrators that a member account reset process was requested
     * via the token being accepted
     * See {@link NSWDPC\Authentication\ResetAccountExtension}
     * Note: this requires a group with the relevant permission to be created, and members assigned
     * @param Member the member that reset their account
     */
    public function sendMfaAccountResetStarted(Member $resettingMember) : bool {
        return $this->sendMfaAccountResetNotification($resettingMember, 'started');
    }


}
