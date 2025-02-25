<?php

namespace LicenseManagerForWooCommerce\Integrations\WooCommerce;

use LicenseManagerForWooCommerce\Enums\LicenseStatus;
use LicenseManagerForWooCommerce\Lists\LicensesList;
use LicenseManagerForWooCommerce\Models\Resources\Generator as GeneratorResourceModel;
use LicenseManagerForWooCommerce\Models\Resources\License as LicenseResourceModel;
use LicenseManagerForWooCommerce\Repositories\Resources\Generator as GeneratorResourceRepository;
use LicenseManagerForWooCommerce\Repositories\Resources\License as LicenseResourceRepository;
use LicenseManagerForWooCommerce\Settings;
use WC_Order_Item_Product;
use WC_Product_Simple;
use function WC;
use WC_Order;
use WC_Order_Item;
use WC_Product;

defined('ABSPATH') || exit;

class Order
{
    /**
     * OrderManager constructor.
     */
    public function __construct()
    {
        $this->addOrderStatusHooks();

        add_action('woocommerce_order_action_lmfwc_send_license_keys', array($this, 'processSendLicenseKeysAction'), 10, 1);
        add_action('woocommerce_order_details_after_order_table',      array($this, 'showBoughtLicenses'),           10, 1);
        add_filter('woocommerce_order_actions',                        array($this, 'addSendLicenseKeysAction'),     10, 2);
        add_action('woocommerce_after_order_itemmeta',                 array($this, 'showOrderedLicenses'),          10, 3);
    }

    /**
     * Hooks the license generation method into the woocommerce order status
     * change hooks.
     */
    private function addOrderStatusHooks()
    {
        $orderStatusSettings = Settings::get('lmfwc_license_key_delivery_options', Settings::SECTION_WOOCOMMERCE);

        // The order status settings haven't been configured.
        if (empty($orderStatusSettings)) {
            return;
        }

        foreach ($orderStatusSettings as $status => $settings) {
            if (array_key_exists('send', $settings)) {
                $value = filter_var($settings['send'], FILTER_VALIDATE_BOOLEAN);

                if ($value) {
                    $filterStatus = str_replace('wc-', '', $status);

                    add_action('woocommerce_order_status_' . $filterStatus, array($this, 'generateOrderLicenses'));
                }
            }
        }
    }

    /**
     * Generates licenses for an order.
     *
     * @param int $orderId
     */
    public function generateOrderLicenses($orderId)
    {
         /** @var WC_Order $order */
        $order = wc_get_order($orderId);

        // Keys have already been generated for this order.
        if ( $order->get_meta( 'lmfwc_order_complete')) {
            return;
        }


        // The given order does not exist
        if (!$order) {
            return;
        }

        /** @var WC_Order_Item $orderItem */
        foreach ($order->get_items() as $orderItem) {
            /** @var WC_Product $product */
            $product = $orderItem->get_product();

            // Skip this product because it's not a licensed product.
            if (! $product->get_meta( 'lmfwc_licensed_product', true)) {
                continue;
            }

            $useStock = $product->get_meta(  'lmfwc_licensed_product_use_stock', true);
            $useGenerator =$product->get_meta(  'lmfwc_licensed_product_use_generator', true);

            // Skip this product because neither selling from stock or from generators is active.
            if (!$useStock && !$useGenerator) {
                continue;
            }

            $deliveredQuantity = absint(
               $product->get_meta( 
                    'lmfwc_licensed_product_delivered_quantity',
                    true
                )
            );

            // Determines how many times should the license key be delivered
            if (!$deliveredQuantity) {
                $deliveredQuantity = 1;
            }

            // Set the needed delivery amount
            $neededAmount = absint($orderItem->get_quantity()) * $deliveredQuantity;

            // Sell license keys through available stock.
            if ($useStock) {
                // Retrieve the available license keys.
                /** @var LicenseResourceModel[] $licenseKeys */
                $licenseKeys = LicenseResourceRepository::instance()->findAllBy(
                    array(
                        'product_id' => $product->get_id(),
                        'status' => LicenseStatus::ACTIVE
                    )
                );

                // Retrieve the current stock amount
                $availableStock = count($licenseKeys);

                // There are enough keys.
                if ($neededAmount <= $availableStock) {
                    // Set the retrieved license keys as "SOLD".
                    apply_filters(
                        'lmfwc_sell_imported_license_keys',
                        $licenseKeys,
                        $orderId,
                        $neededAmount
                    );
                }

                // There are not enough keys.
                else {
                    // Set the available license keys as "SOLD".
                    apply_filters(
                        'lmfwc_sell_imported_license_keys',
                        $licenseKeys,
                        $orderId,
                        $availableStock
                    );

                    // The "use generator" option is active, generate them
                    if ($useGenerator) {
                        $amountToGenerate = $neededAmount - $availableStock;
                        $generatorId = $product->get_meta( 
                            'lmfwc_licensed_product_assigned_generator',
                            true
                        );

                        // Retrieve the generator from the database and set up the args.
                        /** @var GeneratorResourceModel $generator */
                        $generator = GeneratorResourceRepository::instance()->find($generatorId);

                        $licenses = apply_filters('lmfwc_generate_license_keys', $amountToGenerate, $generator);

                        // Save the license keys.
                        apply_filters(
                            'lmfwc_insert_generated_license_keys',
                            $orderId,
                            $product->get_id(),
                            $licenses,
                            LicenseStatus::SOLD,
                            $generator
                        );

                        // TODO: Create a backorder
                    }
                }
            }

            // Sell license keys through the active generator
            else if (!$useStock && $useGenerator) {
                $generatorId = $product->get_meta( 
                    'lmfwc_licensed_product_assigned_generator',
                    true
                );

                // Retrieve the generator from the database and set up the args.
                /** @var GeneratorResourceModel $generator */
                $generator = GeneratorResourceRepository::instance()->find($generatorId);

                // The assigned generator no longer exists
                if (!$generator) {
                    continue;
                }

                $licenses = apply_filters('lmfwc_generate_license_keys', $neededAmount, $generator);

                // Save the license keys.
                apply_filters(
                    'lmfwc_insert_generated_license_keys',
                    $orderId,
                    $product->get_id(),
                    $licenses,
                    LicenseStatus::SOLD,
                    $generator
                );
            }

            // Set the order as complete.
            $order->update_meta_data( 'lmfwc_order_complete', 1);
            $order->save();
            // Set status to delivered if the setting is on.
            if (Settings::get('lmfwc_auto_delivery' , Settings::SECTION_WOOCOMMERCE)) {
                LicenseResourceRepository::instance()->updateBy(
                    array('order_id' => $orderId),
                    array('status' => LicenseStatus::DELIVERED)
                );
            }

            $orderedLicenseKeys = LicenseResourceRepository::instance()->findAllBy(
                array(
                    'order_id' => $orderId
                )
            );

            /** Plugin event, Type: post, Name: order_license_keys */
            do_action(
                'lmfwc_event_post_order_license_keys',
                array(
                    'orderId'  => $orderId,
                    'licenses' => $orderedLicenseKeys
                )
            );
        }
    }

    /**
     * Sends out the ordered license keys.
     *
     * @param WC_Order $order
     */
    public function processSendLicenseKeysAction($order)
    {
        WC()->mailer()->emails['LMFWC_Customer_Deliver_License_Keys']->trigger($order->get_id(), $order);
    }

    /**
     * Displays the bought licenses in the order view inside "My Account" -> "Orders".
     *
     * @param WC_Order $order
     */
    public function showBoughtLicenses($order)
    {
        // Check if the parameter is an order ID and get the WC_Order object if necessary
        if (is_int($order)) {
            $order = wc_get_order($order);
        }
        // Return if the order isn't complete.
        if ($order->get_status() != 'completed'
            && ! $order->get_meta( 'lmfwc_order_complete')
        ) {
            return;
        }

        $data = apply_filters('lmfwc_get_customer_license_keys', $order);

        // No license keys found, nothing to do.
        if (!$data) {
            return;
        }

        // Add missing style.
        if (!wp_style_is('lmfwc_admin_css', $list = 'enqueued' )) {
            wp_enqueue_style('lmfwc_admin_css', LMFWC_CSS_URL . 'main.css');
        }
        echo wp_kses_post(
            wc_get_template_html(
                'myaccount/lmfwc-license-keys.php',
                array(
                    'heading'       => apply_filters('lmfwc_license_keys_table_heading', null),
                    'valid_until'   => apply_filters('lmfwc_license_keys_table_valid_until', null),
                    'data'          => $data,
                    'date_format'   => lmfwc_expiration_format(),
                    'args'          => apply_filters('lmfwc_template_args_myaccount_license_keys', array())
                ),
                '',
                LMFWC_TEMPLATES_DIR
            )
        );

    }

    /**
     * Adds a new order action used to resend the sold license keys.
     *
     * @param array $actions
     *
     * @return array
     */
    public function addSendLicenseKeysAction($actions, $order)
    {
        global $post;

        if (!empty(LicenseResourceRepository::instance()->findAllBy(array('order_id' => $order->get_id())))) {
            $actions['lmfwc_send_license_keys'] = __('Send license key(s) to customer', 'license-manager-for-woocommerce');
        }

        return $actions;
    }


    /**
     * Hook into the WordPress Order Item Meta Box and display the license key(s).
     *
     * @param int                    $itemId
     * @param WC_Order_Item_Product  $item
     * @param WC_Product_Simple|bool $product
     */
    public function showOrderedLicenses($itemId, $item, $product)
    {
        // Not a WC_Order_Item_Product object? Nothing to do...
        if (!($item instanceof WC_Order_Item_Product)) {
            return;
        }
    
        // The product does not exist anymore
        if (!$product) {
            return;
        }
    
        /** @var LicenseResourceModel[] $licenses */
        $licenses = LicenseResourceRepository::instance()->findAllBy(
            array(
                'order_id' => $item->get_order_id(),
                'product_id' => $product->get_id()
            )
        );
    
        // No license keys? Nothing to do...
        if (!$licenses) {
            return;
        }
    
        $html = sprintf('<p>%s:</p>', esc_html__('The following license keys have been sold by this order', 'license-manager-for-woocommerce'));
        $html .= '<ul class="lmfwc-license-list">';
    
        if (!Settings::get('lmfwc_hide_license_keys')) {
            /** @var LicenseResourceModel $license */
            foreach ($licenses as $license) {
                $html .= sprintf(
                    '<li></span> <code class="lmfwc-placeholder">%s</code></li>',
                    esc_html($license->getDecryptedLicenseKey())
                );
            }
    
            $html .= '</ul>';
    
            $html .= '<span class="lmfwc-txt-copied-to-clipboard" style="display: none">' . esc_html__('Copied to clipboard', 'license-manager-for-woocommerce') . '</span>';
        } else {
            /** @var LicenseResourceModel $license */
            foreach ($licenses as $license) {
                $html .= sprintf(
                    '<li><code class="lmfwc-placeholder empty" data-id="%d"></code></li>',
                    esc_attr($license->getId())
                );
            }
    
            $html .= '</ul>';
            $html .= '<p>';
    
            $html .= sprintf(
                '<a class="button lmfwc-license-keys-show-all" data-order-id="%d">%s</a>',
                $item->get_order_id(),
                esc_html__('Show license key(s)', 'license-manager-for-woocommerce')
            );
    
            $html .= sprintf(
                '<a class="button lmfwc-license-keys-hide-all" data-order-id="%d">%s</a>',
                $item->get_order_id(),
                esc_html__('Hide license key(s)', 'license-manager-for-woocommerce')
            );
    
            $html .= sprintf(
                '<img class="lmfwc-spinner" alt="%s" src="%s">',
                esc_attr__('Please wait...', 'license-manager-for-woocommerce'),
                esc_url(LicensesList::SPINNER_URL)
            );
    
            $html .= '<span class="lmfwc-txt-copied-to-clipboard" style="display: none">' . esc_html__('Copied to clipboard', 'license-manager-for-woocommerce') . '</span>';
    
            $html .= '</p>';
        }
    
       echo wp_kses($html, lmfwc_shapeSpace_allowed_html());
    }    
}