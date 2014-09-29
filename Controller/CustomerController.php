<?php
/*************************************************************************************/
/*                                                                                   */
/*      Thelia	                                                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*      email : info@thelia.net                                                      */
/*      web : http://www.thelia.net                                                  */
/*                                                                                   */
/*      This program is free software; you can redistribute it and/or modify         */
/*      it under the terms of the GNU General Public License as published by         */
/*      the Free Software Foundation; either version 3 of the License                */
/*                                                                                   */
/*      This program is distributed in the hope that it will be useful,              */
/*      but WITHOUT ANY WARRANTY; without even the implied warranty of               */
/*      MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the                */
/*      GNU General Public License for more details.                                 */
/*                                                                                   */
/*      You should have received a copy of the GNU General Public License            */
/*	    along with this program. If not, see <http://www.gnu.org/licenses/>.         */
/*                                                                                   */
/*************************************************************************************/
namespace Front\Controller;

use Front\Front;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\Event\Customer\CustomerCreateOrUpdateEvent;
use Thelia\Core\Event\Customer\CustomerLoginEvent;
use Thelia\Core\Event\LostPasswordEvent;
use Thelia\Core\Event\Newsletter\NewsletterEvent;
use Thelia\Core\Security\Authentication\CustomerUsernamePasswordFormAuthenticator;
use Thelia\Core\Security\Exception\AuthenticationException;
use Thelia\Core\Security\Exception\UsernameNotFoundException;
use Thelia\Core\Translation\Translator;
use Thelia\Form\CustomerCreateForm;
use Thelia\Form\CustomerLogin;
use Thelia\Form\CustomerLostPasswordForm;
use Thelia\Form\CustomerPasswordUpdateForm;
use Thelia\Form\CustomerProfileUpdateForm;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Customer;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Model\NewsletterQuery;
use Thelia\Tools\URL;
use Thelia\Log\Tlog;
use Thelia\Core\Security\Exception\WrongPasswordException;

/**
 * Class CustomerController
 * @package Thelia\Controller\Front
 * @author Manuel Raynaud <mraynaud@openstudio.fr>
 */
class CustomerController extends BaseFrontController
{
    use \Thelia\Cart\CartTrait;

    use \Thelia\Tools\RememberMeTrait;

    /**
     * Display the register template if no customer logged
     */
    public function viewLoginAction()
    {
        if ($this->getSecurityContext()->hasCustomerUser()) {
            // Redirect to home page
            return $this->generateRedirect(URL::getInstance()->getIndexPage());
        }

        return $this->render("login");
    }

    /**
     * Display the register template if no customer logged
     */
    public function viewRegisterAction()
    {
        if ($this->getSecurityContext()->hasCustomerUser()) {
            // Redirect to home page
            return $this->generateRedirect(URL::getInstance()->getIndexPage());
        }

        return $this->render("register");
    }

    public function newPasswordAction()
    {
        $message = false;

        $passwordLost = new CustomerLostPasswordForm($this->getRequest());

        if (! $this->getSecurityContext()->hasCustomerUser()) {

            try {

                $form = $this->validateForm($passwordLost);

                $event = new LostPasswordEvent($form->get("email")->getData());

                $this->dispatch(TheliaEvents::LOST_PASSWORD, $event);

                return $this->generateSuccessRedirect($passwordLost);

            } catch (FormValidationException $e) {
                $message = Translator::getInstance()->trans("Please check your input: %s", ['%s' => $e->getMessage()], Front::MESSAGE_DOMAIN);
            } catch (\Exception $e) {
                $message = Translator::getInstance()->trans("Sorry, an error occured: %s", ['%s' => $e->getMessage()], Front::MESSAGE_DOMAIN);
            }

            if ($message !== false) {
                Tlog::getInstance()->error(sprintf("Error during customer creation process : %s. Exception was %s", $message, $e->getMessage()));
            }
        }
        else {
            $message = $this->getTranslator()->trans("You're currently logged in. Please log out before requesting a new password.");
        }

        if ($message !== false) {
            $passwordLost->setErrorMessage($message);

            $this->getParserContext()
                ->addForm($passwordLost)
                ->setGeneralError($message)
            ;
        }
    }

    public function newPasswordSentAction()
    {
        $this->getParser()->assign('password_sent', true);
    }

    /**
     * Create a new customer.
     * On success, redirect to success_url if exists, otherwise, display the same view again.
     */
    public function createAction()
    {
        if (! $this->getSecurityContext()->hasCustomerUser()) {

            $message = false;

            $customerCreation = new CustomerCreateForm($this->getRequest());

            try {
                $form = $this->validateForm($customerCreation, "post");

                $customerCreateEvent = $this->createEventInstance($form->getData());

                $this->dispatch(TheliaEvents::CUSTOMER_CREATEACCOUNT, $customerCreateEvent);

                $newCustomer = $customerCreateEvent->getCustomer();

                // Newsletter
                if (true === $form->get('newsletter')->getData()) {
                    $newsletterEmail = $newCustomer->getEmail();
                    $nlEvent = new NewsletterEvent($newsletterEmail, $this->getRequest()->getSession()->getLang()->getLocale());
                    $nlEvent->setFirstname($newCustomer->getFirstname());
                    $nlEvent->setLastname($newCustomer->getLastname());

                    // Security : Check if this new Email address already exist
                    if (null !== $newsletter = NewsletterQuery::create()->findOneByEmail($newsletterEmail)) {
                        $nlEvent->setId($newsletter->getId());
                        $this->dispatch(TheliaEvents::NEWSLETTER_UPDATE, $nlEvent);
                    } else {
                        $this->dispatch(TheliaEvents::NEWSLETTER_SUBSCRIBE, $nlEvent);
                    }
                }

                $this->processLogin($customerCreateEvent->getCustomer());

                $cart = $this->getCart($this->getDispatcher(), $this->getRequest());
                if ($cart->getCartItems()->count() > 0) {
                    $response = $this->generateRedirectFromRoute('cart.view');
                } else {
                    $response = $this->generateSuccessRedirect($customerCreation);
                }

                return $response;

            } catch (FormValidationException $e) {
                $message = Translator::getInstance()->trans("Please check your input: %s", ['%s' => $e->getMessage()], Front::MESSAGE_DOMAIN);
            } catch (\Exception $e) {
                $message = Translator::getInstance()->trans("Sorry, an error occured: %s", ['%s' => $e->getMessage()], Front::MESSAGE_DOMAIN);
            }

            if ($message !== false) {
                Tlog::getInstance()->error(sprintf("Error during customer creation process : %s. Exception was %s", $message, $e->getMessage()));

                $customerCreation->setErrorMessage($message);

                $this->getParserContext()
                    ->addForm($customerCreation)
                    ->setGeneralError($message)
                ;
            }
        }
    }

    /**
     * Update customer data. On success, redirect to success_url if exists.
     * Otherwise, display the same view again.
     */
    public function viewAction()
    {
        $this->checkAuth();

        $customer = $this->getSecurityContext()->getCustomerUser();
        $data = array(
            'id'           => $customer->getId(),
            'title'        => $customer->getTitleId(),
            'firstname'    => $customer->getFirstName(),
            'lastname'     => $customer->getLastName(),
            'email'        => $customer->getEmail(),
            'newsletter'   => null !== NewsletterQuery::create()->findOneByEmail($customer->getEmail()),
        );

        $customerProfileUpdateForm = new CustomerProfileUpdateForm($this->getRequest(), 'form', $data);

        // Pass it to the parser
        $this->getParserContext()->addForm($customerProfileUpdateForm);
    }

    public function updatePasswordAction()
    {
        if ($this->getSecurityContext()->hasCustomerUser()) {
            $message = false;

            $customerPasswordUpdateForm = new CustomerPasswordUpdateForm($this->getRequest());

            try {
                $customer = $this->getSecurityContext()->getCustomerUser();

                $form = $this->validateForm($customerPasswordUpdateForm, "post");

                $customerChangeEvent = $this->createEventInstance($form->getData());
                $customerChangeEvent->setCustomer($customer);
                $this->dispatch(TheliaEvents::CUSTOMER_UPDATEPROFILE, $customerChangeEvent);

                return $this->generateSuccessRedirect($customerPasswordUpdateForm);

            } catch (FormValidationException $e) {
                $message = Translator::getInstance()->trans("Please check your input: %s", ['%s' => $e->getMessage()], Front::MESSAGE_DOMAIN);
            } catch (\Exception $e) {
                $message = Translator::getInstance()->trans("Sorry, an error occured: %s", ['%s' => $e->getMessage()], Front::MESSAGE_DOMAIN);
            }

            if ($message !== false) {
                Tlog::getInstance()->error(sprintf("Error during customer password modification process : %s.", $message));

                $customerPasswordUpdateForm->setErrorMessage($message);

                $this->getParserContext()
                    ->addForm($customerPasswordUpdateForm)
                    ->setGeneralError($message)
                ;
            }
        }
    }

    public function updateAction()
    {
        if ($this->getSecurityContext()->hasCustomerUser()) {

            $message = false;

            $customerProfileUpdateForm = new CustomerProfileUpdateForm($this->getRequest());

            try {
                $customer = $this->getSecurityContext()->getCustomerUser();
                $newsletterOldEmail = $customer->getEmail();

                $form = $this->validateForm($customerProfileUpdateForm, "post");

                $customerChangeEvent = $this->createEventInstance($form->getData());
                $customerChangeEvent->setCustomer($customer);
                // We do not allow customer email modification
                $customerChangeEvent->setEmailUpdateAllowed(false);
                $this->dispatch(TheliaEvents::CUSTOMER_UPDATEPROFILE, $customerChangeEvent);

                $updatedCustomer = $customerChangeEvent->getCustomer();

                // Newsletter
                if (true === $form->get('newsletter')->getData()) {
                    $nlEvent = new NewsletterEvent($updatedCustomer->getEmail(), $this->getRequest()->getSession()->getLang()->getLocale());
                    $nlEvent->setFirstname($updatedCustomer->getFirstname());
                    $nlEvent->setLastname($updatedCustomer->getLastname());

                    if (null !== $newsletter = NewsletterQuery::create()->findOneByEmail($newsletterOldEmail)) {
                        $nlEvent->setId($newsletter->getId());
                        $this->dispatch(TheliaEvents::NEWSLETTER_UPDATE, $nlEvent);
                    } else {
                        $this->dispatch(TheliaEvents::NEWSLETTER_SUBSCRIBE, $nlEvent);
                    }
                } else {
                    if (null !== $newsletter = NewsletterQuery::create()->findOneByEmail($newsletterOldEmail)) {
                        $nlEvent = new NewsletterEvent($updatedCustomer->getEmail(), $this->getRequest()->getSession()->getLang()->getLocale());
                        $nlEvent->setId($newsletter->getId());
                        $this->dispatch(TheliaEvents::NEWSLETTER_UNSUBSCRIBE, $nlEvent);
                    }
                }

                $this->processLogin($updatedCustomer);

                return $this->generateSuccessRedirect($customerProfileUpdateForm);

            } catch (FormValidationException $e) {
                $message = Translator::getInstance()->trans("Please check your input: %s", ['%s' => $e->getMessage()], Front::MESSAGE_DOMAIN);
            } catch (\Exception $e) {
                $message = Translator::getInstance()->trans("Sorry, an error occured: %s", ['%s' => $e->getMessage()], Front::MESSAGE_DOMAIN);
            }

            if ($message !== false) {
                Tlog::getInstance()->error(sprintf("Error during customer modification process : %s.", $message));

                $customerProfileUpdateForm->setErrorMessage($message);

                $this->getParserContext()
                    ->addForm($customerProfileUpdateForm)
                    ->setGeneralError($message)
                ;
            }
        }
    }

    /**
     * Perform user login. On a successful login, the user is redirected to the URL
     * found in the success_url form parameter, or / if none was found.
     *
     * If login is not successfull, the same view is displayed again.
     *
     */
    public function loginAction()
    {
        if (! $this->getSecurityContext()->hasCustomerUser()) {
            $message = false;

            $request = $this->getRequest();
            $customerLoginForm = new CustomerLogin($request);

            try {

                $form = $this->validateForm($customerLoginForm, "post");

                // If User is a new customer
                if ($form->get('account')->getData() == 0 && !$form->get("email")->getErrors()) {
                    return $this->generateRedirectFromRoute("customer.create.process", array("email" => $form->get("email")->getData()));
                } else {

                    try {

                        $authenticator = new CustomerUsernamePasswordFormAuthenticator($request, $customerLoginForm);

                        $customer = $authenticator->getAuthentifiedUser();

                        $this->processLogin($customer);

                        if (intval($form->get('remember_me')->getData()) > 0) {
                            // If a remember me field if present and set in the form, create
                            // the cookie thant store "remember me" information
                            $this->createRememberMeCookie(
                                $customer,
                                $this->getRememberMeCookieName(),
                                $this->getRememberMeCookieExpiration()
                            );
                        }

                        return $this->generateSuccessRedirect($customerLoginForm);

                    } catch (UsernameNotFoundException $e) {
                        $message = Translator::getInstance()->trans("Wrong email or password. Please try again");
                    } catch (WrongPasswordException $e) {
                        $message = Translator::getInstance()->trans("Wrong email or password. Please try again");
                    } catch (AuthenticationException $e) {
                        $message = Translator::getInstance()->trans("Wrong email or password. Please try again");
                    }

                }

            } catch (FormValidationException $e) {
                $message = Translator::getInstance()->trans("Please check your input: %s", ['%s' => $e->getMessage()], Front::MESSAGE_DOMAIN);
            } catch (\Exception $e) {
                $message = Translator::getInstance()->trans("Sorry, an error occured: %s", ['%s' => $e->getMessage()], Front::MESSAGE_DOMAIN);
            }

            if ($message !== false) {
                Tlog::getInstance()->error(sprintf("Error during customer login process : %s. Exception was %s", $message, $e->getMessage()));

                $customerLoginForm->setErrorMessage($message);

                $this->getParserContext()->addForm($customerLoginForm);
            }
        }
    }

    /**
     * Perform customer logout.
     */
    public function logoutAction()
    {
        if ($this->getSecurityContext()->hasCustomerUser()) {
            $this->dispatch(TheliaEvents::CUSTOMER_LOGOUT);
        }

        $this->clearRememberMeCookie($this->getRememberMeCookieName());

        // Redirect to home page
        return $this->generateRedirect(URL::getInstance()->getIndexPage());
    }

    /**
     * Dispatch event for customer login action
     *
     * @param Customer $customer
     */
    protected function processLogin(Customer $customer)
    {
        $this->dispatch(TheliaEvents::CUSTOMER_LOGIN, new CustomerLoginEvent($customer));
    }

    /**
     * @param $data
     * @return \Thelia\Core\Event\Customer\CustomerCreateOrUpdateEvent
     */
    private function createEventInstance($data)
    {
        $customerCreateEvent = new CustomerCreateOrUpdateEvent(
            isset($data["title"])?$data["title"]:null,
            isset($data["firstname"])?$data["firstname"]:null,
            isset($data["lastname"])?$data["lastname"]:null,
            isset($data["address1"])?$data["address1"]:null,
            isset($data["address2"])?$data["address2"]:null,
            isset($data["address3"])?$data["address3"]:null,
            isset($data["phone"])?$data["phone"]:null,
            isset($data["cellphone"])?$data["cellphone"]:null,
            isset($data["zipcode"])?$data["zipcode"]:null,
            isset($data["city"])?$data["city"]:null,
            isset($data["country"])?$data["country"]:null,
            isset($data["email"])?$data["email"]:null,
            isset($data["password"]) ? $data["password"]:null,
            $this->getRequest()->getSession()->getLang()->getId(),
            isset($data["reseller"])?$data["reseller"]:null,
            isset($data["sponsor"])?$data["sponsor"]:null,
            isset($data["discount"])?$data["discount"]:null,
            isset($data["company"])?$data["company"]:null,
            null
        );

        return $customerCreateEvent;
    }


    protected function getRememberMeCookieName()
    {
        return ConfigQuery::read('customer_remember_me_cookie_name', 'crmcn');
    }

    protected function getRememberMeCookieExpiration()
    {
        return ConfigQuery::read('customer_remember_me_cookie_expiration', 2592000 /* 1 month */);
    }

}
