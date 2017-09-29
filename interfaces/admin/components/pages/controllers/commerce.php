<?php

namespace CASHMusic\Admin;

use CASHMusic\Core\CASHConnection;
use CASHMusic\Core\CASHSystem as CASHSystem;
use CASHMusic\Core\CASHRequest as CASHRequest;
use ArrayIterator;
use CASHMusic\Admin\AdminHelper;
use CASHMusic\Entities\CommerceOrder;

/*******************************************************************************
 *
 * 1. SET UP SCRIPT VARIABLES
 *
 ******************************************************************************/
$admin_helper = new AdminHelper($admin_primary_cash_request, $cash_admin);

$page_data_object = new CASHConnection($admin_helper->getPersistentData('cash_effective_user'));

/*******************************************************************************
 *
 * 2. HANDLE ORDERS MARKED AS FULFILLED
 *
 ******************************************************************************/
if (isset($_REQUEST['fulfill'])) {
	$order_details_response = $cash_admin->requestAndStore(
		array(
			'cash_request_type' => 'commerce',
			'cash_action' => 'editorder',
			'id' => $_REQUEST['fulfill'],
			'fulfilled' => 1
		)
	);
	if ($request_parameters) {
		$addtourl = implode('/',$request_parameters);
	} else {
		$addtourl = '';
	}
}

/*******************************************************************************
 *
 * 3. SECTION SETTINGS
 *
 ******************************************************************************/
if (isset($_REQUEST['currency_id'])) {
	$settings_response = $cash_admin->requestAndStore(
		array(
			'cash_request_type' => 'system',
			'cash_action' => 'setsettings',
			'type' => 'use_currency',
			'value' => $_REQUEST['currency_id'],
			'user_id' => $cash_admin->effective_user_id
		)
	);

	$settings_response = $cash_admin->requestAndStore(
		array(
			'cash_request_type' => 'system',
			'cash_action' => 'setsettings',
			'type' => 'payment_defaults',
			'value' => array(
				'pp_default' => $_REQUEST['paypal_default_id'],
				'pp_micro' => $_REQUEST['paypal_micropayment_id'],
				'stripe_default' => $_REQUEST['stripe_default']
			),
			'user_id' => $cash_admin->effective_user_id
		)
	);
	if ($settings_response['payload']) {
        $admin_helper->formSuccess('Success.','/commerce/');
	}
}
// now get the current currency setting
$settings_response = $cash_admin->requestAndStore(
	array(
		'cash_request_type' => 'system',
		'cash_action' => 'getsettings',
		'type' => 'use_currency',
		'user_id' => $cash_admin->effective_user_id
	)
);
if ($settings_response['payload']) {
	$current_currency = $settings_response['payload'];
} else {
	$current_currency = 'USD';
}
$cash_admin->page_data['currency_options'] = AdminHelper::echoCurrencyOptions($current_currency);
// current paypal
$settings_response = $cash_admin->requestAndStore(
	array(
		'cash_request_type' => 'system',
		'cash_action' => 'getsettings',
		'type' => 'payment_defaults',
		'user_id' => $cash_admin->effective_user_id
	)
);

if (is_array($settings_response['payload'])) {
	$pp_default = $settings_response['payload']['pp_default'];
	$pp_micro = $settings_response['payload']['pp_micro'];
	$stripe_selected = $settings_response['payload']['stripe_default'];
} else {
	$pp_default = 0;
	$pp_micro = 0;
	$stripe_selected = 0;
}
$cash_admin->page_data['currency_options'] = AdminHelper::echoCurrencyOptions($current_currency);

$pp = array();
$allpp = $page_data_object->getConnectionsByType('com.paypal');


if (is_array($allpp)) {
	foreach ($allpp as $ppq) {
		$pp[$ppq->id] = $ppq->name;
	}
}
$cash_admin->page_data['paypal_default_options'] = $admin_helper->echoFormOptions($pp,$pp_default,false,true,true);
$cash_admin->page_data['paypal_micro_options'] = $admin_helper->echoFormOptions($pp,$pp_micro,false,true,true);

// admin stripe defaults
$stripe = array();
$allstripe = $page_data_object->getConnectionsByType('com.stripe');
if (is_array($allstripe)) {
	foreach ($allstripe as $stripeq) {
		$stripe[$stripeq->id] = $stripeq->name;
	}
}

$stripe = array_unique($stripe);
$change_default = false;

// there's no default stripe set... see if we've got connections and set the default to the latest connection
if ((!array_key_exists($stripe_selected, $stripe)) && count($stripe) > 0) {
	$change_default = true;
	// stripe
    end($stripe);
    $newest_stripe_connection = key($stripe);

    $stripe_selected = $newest_stripe_connection;
}

if ($change_default) {
    $cash_admin->requestAndStore(
        array(
            'cash_request_type' => 'system',
            'cash_action' => 'setsettings',
            'type' => 'payment_defaults',
            'value' => array(
                'pp_default' => $pp_default,
                'pp_micro' => $pp_micro,
                'stripe_default' => $stripe_selected
            ),
            'user_id' => $cash_admin->effective_user_id
        )
    );
}

$cash_admin->page_data['stripe_options'] = $admin_helper->echoFormOptions($stripe,$stripe_selected,false,true,true);


// handle regions
if (isset($_REQUEST['region1'])) {
	$regions = array(
		'region1' => $_REQUEST['region1'],
		'region2' => $_REQUEST['region2']
	);
	$settings_response = $cash_admin->requestAndStore(
		array(
			'cash_request_type' => 'system',
			'cash_action' => 'setsettings',
			'type' => 'regions',
			'value' => $regions,
			'user_id' => $cash_admin->effective_user_id
		)
	);
	if ($settings_response['payload']) {
        $admin_helper->formSuccess('Success.','/commerce/');
	}
}
// now get the current setting
$settings_response = $cash_admin->requestAndStore(
	array(
		'cash_request_type' => 'system',
		'cash_action' => 'getsettings',
		'type' => 'regions',
		'user_id' => $cash_admin->effective_user_id
	)
);

if ($settings_response['payload']) {
	$cash_admin->page_data['region1'] = $settings_response['payload']['region1'];
	$cash_admin->page_data['region2'] = $settings_response['payload']['region2'];
} else {
	$cash_admin->page_data['noshippingregions'] = true;
}

/*******************************************************************************
 *
 * 4. PAGE VIEW FILTERS, GET ORDERS FOR SPECIFIED VIEW
 *
 ******************************************************************************/
$cash_admin->page_data['current_page'] = 1;
$cash_admin->page_data['next_page'] = 2;
$cash_admin->page_data['show_previous'] = false;
$filter = false;

$cash_admin->page_data['no_filter'] = true;
if ($request_parameters) {
	$filter_key = array_search('filter', $request_parameters);
	if ($filter_key !== false) {
		$filter = $request_parameters[$filter_key + 1];
		$cash_admin->page_data['no_filter'] = false;
		$cash_admin->page_data['filter_type'] = $filter;
		if ($filter == 'week') {
			$cash_admin->page_data['filter_week'] = true;
		} else if ($filter == 'all') {
			$cash_admin->page_data['filter_all'] = true;
		}
	}

	$page_key = array_search('page', $request_parameters);
	if ($page_key !== false) {
		$cash_admin->page_data['current_page'] = $request_parameters[$page_key + 1];
		$cash_admin->page_data['next_page'] = $request_parameters[$page_key + 1] + 1;
		$cash_admin->page_data['previous_page'] = $request_parameters[$page_key + 1] - 1;
		if ($cash_admin->page_data['previous_page'] == 1) {
			$cash_admin->page_data['back_to_first'] = true;
		}
		$cash_admin->page_data['show_pagination'] = true;
		$cash_admin->page_data['show_previous'] = true;
	}
} else {
	$cash_admin->page_data['no_filter'] = true;
}

$order_request = array(
	'cash_request_type' => 'commerce',
	'cash_action' => 'getordersforuser',
	'user_id' => $cash_admin->effective_user_id,
	'max_returned' => 20,
	'skip' => ($cash_admin->page_data['current_page'] - 1) * 18,
	'deep' => true
);


if ($cash_admin->page_data['no_filter']) {
	$order_request['unfulfilled_only'] = 1;
}
if ($filter == 'week') {
	$order_request['since_date'] = time() - 604800;
}
if ($filter == 'byitem') {
	$order_request['cash_action'] = 'getordersbyitem';
	$order_request['item_id'] = $request_parameters[$filter_key + 2];
	$cash_admin->page_data['filter_item_id'] = $order_request['item_id'];
}

$orders_response = $cash_admin->requestAndStore($order_request);
/*******************************************************************************
 *
 * 5. GET ALL VALID SERVICE CONNECTIONS FOR FIRST-USE
 *
 ******************************************************************************/
$cash_admin->page_data['connection'] = $admin_helper->getConnectionsByScope('commerce');

if (!$cash_admin->page_data['connection']) {
	if (!is_array($orders_response['payload'])) {
		$cash_admin->page_data['firstuse'] = true;
		$settings_types_data = $page_data_object->getConnectionTypes('commerce');

		$all_services = array();
		$typecount = 1;
		foreach ($settings_types_data as $key => $data) {
			if ($typecount % 2 == 0) {
				$alternating_type = true;
			} else {
				$alternating_type = false;
			}
			if (file_exists(ADMIN_BASE_PATH.'/assets/images/settings/' . $key . '.png')) {
				$service_has_image = true;
			} else {
				$service_has_image = false;
			}
			if (in_array($cash_admin->platform_type, $data['compatibility'])) {
				$all_services[] = array(
					'key' => $key,
					'name' => $data['name'],
					'description' => $data['description'],
					'link' => $data['link'],
					'alternating_type' => $alternating_type,
					'service_has_image' => $service_has_image
				);
				$typecount++;
			}
		}
		$cash_admin->page_data['all_services'] = new ArrayIterator($all_services);
	}
}

/*******************************************************************************
 *
 * 6. CLEAN UP ORDERS
 *
 ******************************************************************************/

if (is_array($orders_response['payload'])) {
	$all_order_details = array();
	foreach ($orders_response['payload'] as $o) {


		if ($o['successful']) {
			$order_date = $o['creation_date'];
			$item_price = 0;
            $order_contents = [];
			if (!empty($o['order_contents'])) {
                $order_contents = $o['order_contents'];
				if (!is_array($order_contents)) {
                    $order_contents = json_decode($order_contents, true);
				}
			}

			if (is_array($order_contents)) {
                foreach ($order_contents as $key => $item) {
                    if (!isset($item['qty'])) {
                        $item['qty'] = 1;
                    }
                    $item_price += $item['qty'] * $item['price'];

                    if (isset($item['variant'])) {
                        $variant_response = $cash_admin->requestAndStore(
                            array(
                                'cash_request_type' => 'commerce',
                                'cash_action' => 'formatvariantname',
                                'name' => $item['variant']
                            )
                        );
                        if ($variant_response['payload']) {
                            $order_contents[$key]['variant'] = $variant_response['payload'];
                        }
                    }
                }
            }

			if ($o['gross_price'] - $item_price) {
				$shipping_cost = CASHSystem::getCurrencySymbol($o['currency']) . number_format($o['gross_price'] - $item_price,2);
				$item_price = CASHSystem::getCurrencySymbol($o['currency']) . number_format($item_price,2);
			} else {
				$shipping_cost = false;
			}

			$customer_name = "";
			if (isset($o['customer_first_name'],$o['customer_last_name'])) {
				$customer_name = $o['customer_first_name'] . " " . $o['customer_last_name'];
			}


			if (!empty($o['customer_shipping_name'])) {
                $all_order_details[] = array(
                    'id' => $o['id'],
                    'customer_name' => $customer_name,
                    'customer_shipping_name' => $o['customer_shipping_name'],
                    'customer_email' => $o['customer_email'],
                    'customer_address1' => $o['customer_address1'],
                    'customer_address2' => $o['customer_address2'],
                    'customer_city' => $o['customer_city'],
                    'customer_region' => $o['customer_region'],
                    'customer_postalcode' => $o['customer_postalcode'],
                    'customer_country' => $o['customer_countrycode'],
                    'number' => '#' . str_pad($o['id'], 6, 0, STR_PAD_LEFT),
                    'date' => CASHSystem::formatTimeAgo((int)$o['creation_date'], true),
                    'order_description' => str_replace("\n", ' ', $o['order_description']),
                    'order_contents' => ($order_contents) ? new ArrayIterator($order_contents) : false,
                    'shipping' => $shipping_cost,
                    'itemtotal' => $item_price,
                    'gross' => CASHSystem::getCurrencySymbol($o['currency']) . number_format($o['gross_price'], 2),
                    'fulfilled' => $o['fulfilled'],
                    'notes' => $o['notes'],
                    'canceled' => $o['canceled']
                );
            } else {
				//if empty
				//CASHSystem::errorLog($o);
			}
		}

	}



	$cash_admin->page_data['has_orders'] = false;


	if (count($all_order_details) > 0) {
	$cash_admin->page_data['has_orders'] = true;
		if (count($all_order_details) > 10) {
			$cash_admin->page_data['show_pagination'] = true;
			$cash_admin->page_data['show_next'] = true;
			if ($cash_admin->page_data['show_previous']) {
				$cash_admin->page_data['show_nextandprevious'] = true;
			}
			array_pop($all_order_details);
		}
		$cash_admin->page_data['orders_recent'] = new ArrayIterator($all_order_details);
		$cash_admin->page_data['show_filters'] = true;
		$cash_admin->page_data['has_orders'] = true;
	}
}

/*******************************************************************************
 *
 * 7. SET THE TEMPLATE AND GO!
 *
 ******************************************************************************/
$cash_admin->setPageContentTemplate('commerce');
?>
