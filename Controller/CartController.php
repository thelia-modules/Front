<?php
/*************************************************************************************/
/*                                                                                   */
/*      Thelia	                                                                     */
/*                                                                                   */
/*      Copyright (c) OpenStudio                                                     */
/*	    email : info@thelia.net                                                      */
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

use Propel\Runtime\Exception\PropelException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\Event\Cart\CartEvent;
use Thelia\Core\Event\Order\OrderEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Form\CartAdd;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Log\Tlog;
use Thelia\Model\AddressQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Module\Exception\DeliveryException;
use Thelia\Tools\URL;

class CartController extends BaseFrontController
{
    use \Thelia\Cart\CartTrait;

    public function addItem()
    {
        $request = $this->getRequest();

        $cartAdd = $this->getAddCartForm($request);
        $message = null;

        try {
            $form = $this->validateForm($cartAdd);

            $cartEvent = $this->getCartEvent();
            $cartEvent->setNewness($form->get("newness")->getData());
            $cartEvent->setAppend($form->get("append")->getData());
            $cartEvent->setQuantity($form->get("quantity")->getData());
            $cartEvent->setProductSaleElementsId($form->get("product_sale_elements_id")->getData());
            $cartEvent->setProduct($form->get("product")->getData());

            $this->getDispatcher()->dispatch(TheliaEvents::CART_ADDITEM, $cartEvent);

            $this->afterModifyCart();

            $this->redirectSuccess();

        } catch (PropelException $e) {
            Tlog::getInstance()->error(sprintf("Failed to add item to cart with message : %s", $e->getMessage()));
            $message = "Failed to add this article to your cart, please try again";
        } catch (FormValidationException $e) {
            $message = $e->getMessage();
        }

        // If Ajax Request
        if ($this->getRequest()->isXmlHttpRequest()) {
            $request = $this->getRequest();
            $request->attributes->set('_view', "includes/mini-cart");
        }

        if ($message) {
            $cartAdd->setErrorMessage($message);
            $this->getParserContext()->addForm($cartAdd);
        }
    }

    public function changeItem()
    {
        $cartEvent = $this->getCartEvent();
        $cartEvent->setCartItem($this->getRequest()->get("cart_item"));
        $cartEvent->setQuantity($this->getRequest()->get("quantity"));

        try {
            $this->dispatch(TheliaEvents::CART_UPDATEITEM, $cartEvent);

            $this->afterModifyCart();

            $this->redirectSuccess();
        } catch (PropelException $e) {
            $this->getParserContext()->setGeneralError($e->getMessage());
        }

    }

    public function deleteItem()
    {
        $cartEvent = $this->getCartEvent();
        $cartEvent->setCartItem($this->getRequest()->get("cart_item"));

        try {
            $this->getDispatcher()->dispatch(TheliaEvents::CART_DELETEITEM, $cartEvent);

            $this->afterModifyCart();

            $this->redirectSuccess();
        } catch (PropelException $e) {
            Tlog::getInstance()->error(sprintf("error during deleting cartItem with message : %s", $e->getMessage()));
            $this->getParserContext()->setGeneralError($e->getMessage());
        }

    }

    public function changeCountry()
    {
        $redirectUrl = URL::getInstance()->absoluteUrl("/cart");
        $deliveryId = $this->getRequest()->get("country");
        $cookieName = ConfigQuery::read('front_cart_country_cookie_name', 'fcccn');
        $cookieExpires = ConfigQuery::read('front_cart_country_cookie_expires', 2592000);
        $cookieExpires = intval($cookieExpires) ?: 2592000;

        $cookie = new Cookie($cookieName, $deliveryId, time() + $cookieExpires, '/');

        $this->redirect($redirectUrl, 302, array($cookie));
    }

    /**
     * use Thelia\Cart\CartTrait for searching current cart or create a new one
     *
     * @return \Thelia\Core\Event\Cart\CartEvent
     */
    protected function getCartEvent()
    {
        $cart = $this->getCart($this->getDispatcher(), $this->getRequest());

        return new CartEvent($cart);
    }

    /**
     * Find the good way to construct the cart form
     *
     * @param  Request $request
     * @return CartAdd
     */
    private function getAddCartForm(Request $request)
    {
        if ($request->isMethod("post")) {
            $cartAdd = new CartAdd($request);
        } else {
            $cartAdd = new CartAdd(
                $request,
                "form",
                array(),
                array(
                    'csrf_protection'   => false,
                )
            );
        }

        return $cartAdd;
    }

    protected function afterModifyCart()
    {
        /* recalculate postage amount */
        $order = $this->getSession()->getOrder();
        if (null !== $order) {
            $deliveryModule = $order->getModuleRelatedByDeliveryModuleId();
            $deliveryAddress = AddressQuery::create()->findPk($order->chosenDeliveryAddress);

            if (null !== $deliveryModule && null !== $deliveryAddress) {
                $moduleInstance = $this->container->get(sprintf('module.%s', $deliveryModule->getCode()));

                $orderEvent = new OrderEvent($order);

                try {
                    $postage = $moduleInstance->getPostage($deliveryAddress->getCountry());

                    $orderEvent->setPostage($postage);

                    $this->getDispatcher()->dispatch(TheliaEvents::ORDER_SET_POSTAGE, $orderEvent);
                }
                catch (DeliveryException $ex) {
                    // The postage has been chosen, but changes in the cart causes an exception.
                    // Reset the postage data in the order
                    $orderEvent->setDeliveryModule(0);

                    $this->getDispatcher()->dispatch(TheliaEvents::ORDER_SET_DELIVERY_MODULE, $orderEvent);
                }
            }
        }
    }
}
