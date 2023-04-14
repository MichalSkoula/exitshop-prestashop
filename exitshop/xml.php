<?php

if (!isset($_GET['country']) || strlen($_GET['country']) != 2 || !isset($_GET['from'])) {
    die("wrong parameters");
}

$date_from = date('Y-m-d', strtotime($_GET['from']));
$date_to = date('Y-m-d H:i:s', strtotime("-1 minutes")); // one minute age, let PS create whole order for sure

require("../../config/settings.inc.php");
require("../../config/config.inc.php");

//connect do database
$mysqli = new mysqli(_DB_SERVER_, _DB_USER_, _DB_PASSWD_, _DB_NAME_);
if ($mysqli->connect_error) {
    die('cannot connect to sql');
}
$mysqli->query("SET NAMES 'utf8'");

//get configuration
$conf = array();
$query = $mysqli->query("
	SELECT * 
	FROM "._DB_PREFIX_."configuration 
	WHERE name LIKE 'exit_allow_xml' AND id_shop IS NULL AND id_shop_group IS NULL
");
while ($row = $query->fetch_assoc()) {
    $conf[$row['name']] = $row['value'];
}
if ((int)$conf['exit_allow_xml'] !== 1) {
    die('xml not allowed');
}

//get shops from get
$shops = array();
$result = $mysqli->query("
	SELECT * 
	FROM "._DB_PREFIX_."lang l 
	JOIN "._DB_PREFIX_."lang_shop ls ON ls.id_lang=l.id_lang
	WHERE iso_code='".pSQL($_GET['country'])."'
");
while ($row = $result->fetch_assoc()) {
    $shops[] = $row['id_shop'];
}

//zasilkovna?
$zasilkovna = false;
$result = $mysqli->query("
	SELECT * 
	FROM information_schema.tables t
	WHERE t.TABLE_SCHEMA='"._DB_NAME_."' AND t.TABLE_NAME='"._DB_PREFIX_."packetery_order'
	LIMIT 1
");
if ($result->num_rows == 1) {
    $zasilkovna = true;
}

//gopay?
$gopay = false;
$result = $mysqli->query("
    SELECT * 
    FROM information_schema.tables t
    WHERE t.TABLE_SCHEMA='"._DB_NAME_."' AND t.TABLE_NAME='"._DB_PREFIX_."add_gopay_new_order'
    LIMIT 1
");
if ($result->num_rows == 1) {
    $gopay = true;
}

//pribalit?
$pribalit = false;
$result = $mysqli->query("
	SHOW COLUMNS FROM "._DB_PREFIX_."cart LIKE 'pribalit';
");
if ($result->num_rows == 1) {
    $pribalit = true;
}

//get orders
$orders = $mysqli->query("
	SELECT 
		o.id_order,o.date_add,o.id_order,o.total_shipping_tax_incl,o.date_add,o.payment,
		cus.email,
		cur.sign AS currency,
		car.name AS shipping,
		m.message,
		".($pribalit ? "c.pribalit," : "")."
		CONCAT(ad.firstname,' ',ad.lastname) AS name,ad.address1 AS street,ad.dni AS ic,ad.postcode AS zip,ad.city,IF(CHAR_LENGTH(ad.phone_mobile) > 2,ad.phone_mobile,ad.phone) AS phone, ad.vat_number AS dic,
		CONCAT(ai.firstname,' ',ai.lastname) AS fa_name,ai.address1 AS fa_street,ai.dni AS fa_ic,ai.postcode AS fa_zip,ai.city AS fa_city,IF(CHAR_LENGTH(ai.phone_mobile) > 2,ai.phone_mobile,ai.phone) AS fa_phone,ai.vat_number AS fa_dic, ai.company AS fa_company
		".($zasilkovna ? ",po.id_branch,po.name_branch" : "")."
        ".($gopay ? ",agno.id_session AS gopay_id, agno.payment_status AS gopay_status" : "")."
	FROM "._DB_PREFIX_."orders o 
	JOIN "._DB_PREFIX_."address ad ON o.id_address_delivery=ad.id_address
	JOIN "._DB_PREFIX_."address ai ON o.id_address_invoice=ai.id_address
	JOIN "._DB_PREFIX_."customer cus ON cus.id_customer=o.id_customer
	LEFT JOIN "._DB_PREFIX_."carrier car ON car.id_carrier=o.id_carrier AND car.active=1
	LEFT JOIN "._DB_PREFIX_."carrier_tax_rules_group_shop ctrgs ON ctrgs.id_carrier=o.id_carrier AND ctrgs.id_shop IN (".implode(",", $shops).")
	JOIN "._DB_PREFIX_."currency cur ON cur.id_currency=o.id_currency
	".($pribalit ? "JOIN "._DB_PREFIX_."cart c ON c.id_cart=o.id_cart" : "")."
	LEFT JOIN "._DB_PREFIX_."message m ON m.id_order=o.id_order
	".($zasilkovna ? "LEFT JOIN "._DB_PREFIX_."packetery_order po ON po.id_order=o.id_order" : "")."
    ".($gopay ? "LEFT JOIN "._DB_PREFIX_."add_gopay_new_order agno ON agno.id_order=o.id_order" : "")."
	WHERE o.date_add >= '".pSQL($date_from)."' AND o.date_add <= '".pSQL($date_to)."' AND o.id_shop IN (".implode(",", $shops).")
	GROUP BY o.id_order
	ORDER BY o.id_order
");

header('Content-type: text/xml');
header('Pragma: public');
header('Cache-control: private');
header('Expires: -1');
echo "<?xml version=\"1.0\" encoding=\"utf-8\"?>";

echo "<orders>";

//go through orders
while ($row_order = $orders->fetch_assoc()) {
    echo "<order>";
    echo "<id>".$row_order['id_order']."</id>";
    echo "<date>".$row_order['date_add']."</date>";
    echo "<currency>".$row_order['currency']."</currency>";
    echo "<email><![CDATA[".$row_order['email']."]]></email>";
    echo "<payment>".$row_order['payment']."</payment>";
    echo "<shipping>".$row_order['shipping']."</shipping>";
    if ($zasilkovna) {
        echo "<branch>zasilkovna</branch>";
        echo "<id_branch>".$row_order['id_branch']."</id_branch>";
        echo "<name_branch><![CDATA[".$row_order['name_branch']."]]></name_branch>";
    }
    if ($gopay) {
        echo "<gopay_id>".$row_order['gopay_id']."</gopay_id>";
        echo "<gopay_status>".$row_order['gopay_status']."</gopay_status>";
    }
    if ($pribalit) {
        echo "<add_to_pack>".$row_order['pribalit']."</add_to_pack>";
    }
    echo "<shipping_price>".$row_order['total_shipping_tax_incl']."</shipping_price>";
    echo "<shipping_name><![CDATA[".$row_order['name']."]]></shipping_name>";
    echo "<shipping_street><![CDATA[".$row_order['street']."]]></shipping_street>";
    echo "<shipping_zip>".$row_order['zip']."</shipping_zip>";
    echo "<shipping_city><![CDATA[".$row_order['city']."]]></shipping_city>";
    echo "<shipping_phone>".$row_order['phone']."</shipping_phone>";
    echo "<shipping_ic>".$row_order['ic']."</shipping_ic>";
    echo "<shipping_dic>".$row_order['dic']."</shipping_dic>";
    echo "<fa_name><![CDATA[".$row_order['fa_name']."]]></fa_name>";
    echo "<fa_street><![CDATA[".$row_order['fa_street']."]]></fa_street>";
    echo "<fa_zip>".$row_order['fa_zip']."</fa_zip>";
    echo "<fa_city><![CDATA[".$row_order['fa_city']."]]></fa_city>";
    echo "<fa_phone>".$row_order['fa_phone']."</fa_phone>";
    echo "<fa_ic>".$row_order['fa_ic']."</fa_ic>";
    echo "<fa_dic>".$row_order['fa_dic']."</fa_dic>";
    echo "<fa_company><![CDATA[".$row_order['fa_company']."]]></fa_company>";
    echo "<message><![CDATA[".$row_order['message']."]]></message>";
    echo "<vouchers>";
    $vouchers = $mysqli->query("
				SELECT name,value,value_tax_excl
				FROM "._DB_PREFIX_."order_cart_rule
				WHERE id_order=".pSQL($row_order['id_order'])."
			");

    //go throught vouchers
    while ($row_voucher = $vouchers->fetch_assoc()) {
        echo "<voucher>";
        echo "<value>".$row_voucher['value']."</value>";
        echo "<value_tax_excl>".$row_voucher['value_tax_excl']."</value_tax_excl>";
        echo "<name><![CDATA[".$row_voucher['name']."]]></name>";
        echo "</voucher>";
    }
    echo "</vouchers>";
    echo "<products>";
    $products = $mysqli->query("
				SELECT 
					od.product_id, od.product_quantity, od.unit_price_tax_incl AS product_price,od.product_name,od.product_reference,
					t.rate AS tax
				FROM "._DB_PREFIX_."orders o 
				JOIN "._DB_PREFIX_."order_detail od ON o.id_order=od.id_order
				JOIN "._DB_PREFIX_."product_shop ps ON ps.id_product=od.product_id AND ps.id_shop IN (".implode(",", $shops).")
				JOIN "._DB_PREFIX_."tax_rule tr ON tr.id_tax_rules_group=ps.id_tax_rules_group
				JOIN "._DB_PREFIX_."tax t ON t.id_tax=tr.id_tax AND t.deleted=0
				WHERE o.id_order=".pSQL($row_order['id_order'])."
				GROUP BY od.id_order_detail
			");

    //go throught products
    while ($row_product = $products->fetch_assoc()) {
        echo "<product>";
        echo "<reference>".$row_product['product_reference']."</reference>";
        echo "<name><![CDATA[".$row_product['product_name']."]]></name>";
        echo "<price>".$row_product['product_price']."</price>";
        echo "<tax>".$row_product['tax']."</tax>";
        echo "<quantity>".$row_product['product_quantity']."</quantity>";
        echo "</product>";
    }
    echo "</products>";
    echo "</order>";
}

echo "</orders>";
