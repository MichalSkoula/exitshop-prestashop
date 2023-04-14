<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body>
<?php

echo "<h1>ExitShop update</h1>";
var_dump($_GET);

$start = microtime(true);

require("../../config/settings.inc.php");
require("../../config/config.inc.php");

set_time_limit(1800);
error_reporting(E_ALL);
ini_set('display_errors', 1);

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
    WHERE id_shop IS NULL AND id_shop_group IS NULL
");
while ($row = $query->fetch_assoc()) {
    $conf[$row['name']] = $row['value'];
}

if (!$conf['exit_update_old']) {
    die("update not allowed");
}

//get langs
$langs = array();
$query = $mysqli->query("
    SELECT l.iso_code, l.id_lang, l.name
    FROM "._DB_PREFIX_."lang l
    WHERE l.active=1
");
while ($row = $query->fetch_assoc()) {
    $langs[$row['id_lang']] = $row['iso_code'];
}

//automatic category 1/3 - some definitions
if (isset($_GET['category'])) {
    $automatic_category_remain = $automatic_out_of_stock_remain = $automatic_on_sale = array(999999999);
    $exit_reference = explode(";", $conf['exit_reference']);
    $exit_reference2 = explode(";", $conf['exit_reference2']);
}

//download XML
if (!isset($conf['exit_primary_url']) || strlen($conf['exit_primary_url']) < 5) {
    die("error - xml not specified");
}
$xml = simplexml_load_file($conf['exit_primary_url']);
if ($xml === false || count($xml) < 1 || !isset($xml->primary)) {
    die("error - xml failed to load");
}
$xml->asXml('feed.xml');

//fetch all products and variants with reference
$ps_products = array();
$query = $mysqli->query("
    SELECT 
        reference,
        id_product AS real_id_product, '-1' AS id_product,
        id_product_attribute
    FROM "._DB_PREFIX_."product_attribute
    WHERE reference IS NOT NULL AND reference != ''

    UNION

    SELECT 
        reference,
        id_product AS real_id_product, id_product, 
        '-1' AS id_product_attribute
    FROM "._DB_PREFIX_."product
    WHERE reference IS NOT NULL AND reference != ''
");
while ($row = $query->fetch_assoc()) {
    $ps_products[(int)$row['reference']] = $row;
}

//foreach products in XML -----------------------------------------------------------------------
$xml_primarys = array();
$count = 0;

//run only for half / quarter products?
$current_hour = date('H');
$modificator = 2;
$update_only_part = count($xml->primary) > 5000;

foreach ($xml->primary as $primary) {
    $xml_primarys[] = (int)$primary->primary_id;

    // update?
    if (isset($ps_products[(int)$primary->primary_id])) {
        $count++;

        $row = $ps_products[(int)$primary->primary_id];
        $id_product_attribute = ((int)$row['id_product_attribute'] === -1) ? 0 : (int)$row['id_product_attribute'];
        $id_product = (int)$row['real_id_product'];

        // update this product?
        $update_this_product = $update_only_part ? ($row['real_id_product'] % $modificator == $current_hour % $modificator) : true;

        //automatic category 2/3 - add new ones
        if (isset($_GET['category'])) {
            $reference = (string)$primary->reference;
            if (isset($conf['exit_reference_status']) && $conf['exit_reference_status'] == 1 && $reference != "") {
                //to category
                if (in_array($reference, $exit_reference)) {
                    $automatic_category_remain[] = $id_product;
                    $automatic_out_of_stock_remain[] = $id_product;
                    $automatic_on_sale[] = $id_product;
                    //much products? lets update only half...
                    if ($update_this_product) {
                        $mysqli->query(" 
                            INSERT IGNORE INTO "._DB_PREFIX_."category_product
                            (id_category,id_product)
                            VALUES
                            (".((int)$conf['exit_reference_category']).",".$id_product.")
                        ");
                    }
                }
                //out of stock change
                if (in_array($reference, $exit_reference) || in_array($reference, $exit_reference2)) {
                    if ($conf['PS_ORDER_OUT_OF_STOCK'] == 1 && $conf['exit_reference_deactivate'] == 1) {
                        $automatic_out_of_stock_remain[] = $id_product;
                        if ($update_this_product) {
                            $mysqli->query("UPDATE "._DB_PREFIX_."stock_available SET out_of_stock=0 WHERE id_product=".$id_product);
                            $mysqli->query("UPDATE "._DB_PREFIX_."product SET out_of_stock=0 WHERE id_product=".$id_product);
                        }
                    }
                }
            }
        }

        //much products? lets update only half...
        if (!$update_this_product) {
            continue;
        }
        
        //update quantity
        if (isset($_GET['quantity'])) {
            $mysqli->query("
                UPDATE "._DB_PREFIX_."stock_available 
                SET quantity=".$primary->quantity.", fake_quantity=".$primary->fake_quantity." 
                WHERE id_product_attribute=".$row['id_product_attribute']." OR id_product=".$row['id_product']
            );
        }

        //update price
        if (isset($_GET['price'])) {
            foreach ($primary->pricelist->price as $price) {
                if ((float)$price->vat_inc <= 0) {
                    continue;
                }

                //product
                if ((int)$row['id_product_attribute'] === -1) {
                    $mysqli->query("
                        UPDATE "._DB_PREFIX_."product_shop ps
                        JOIN "._DB_PREFIX_."currency_shop cs ON cs.id_shop=ps.id_shop
                        JOIN "._DB_PREFIX_."currency c ON c.id_currency=cs.id_currency AND c.active=1
                        JOIN "._DB_PREFIX_."tax_rule tr ON tr.id_tax_rules_group=ps.id_tax_rules_group
                        JOIN "._DB_PREFIX_."tax t ON t.id_tax=tr.id_tax
                        SET price = ".(float)$price->vat_inc." / (1+t.rate/100) 
                        WHERE ps.id_product = ".$row['id_product']." AND c.iso_code='".$price->code."'
                    ");
                }
                //variant
                else {
                    $mysqli->query("
                        UPDATE "._DB_PREFIX_."product_attribute_shop pas
                        JOIN "._DB_PREFIX_."currency_shop cs ON cs.id_shop=pas.id_shop
                        JOIN "._DB_PREFIX_."currency c ON c.id_currency=cs.id_currency AND c.active=1
                        JOIN "._DB_PREFIX_."product_shop ps ON ps.id_product=pas.id_product
                        JOIN "._DB_PREFIX_."tax_rule tr ON tr.id_tax_rules_group=ps.id_tax_rules_group
                        JOIN "._DB_PREFIX_."tax t ON t.id_tax=tr.id_tax
                        SET pas.price = ".(float)$price->vat_inc." / (1+t.rate/100), ps.price=0
                        WHERE pas.id_product_attribute = ".$row['id_product_attribute']." AND c.iso_code='".$price->code."'
                    ");
                }
            }
        }

        //update reduction?
        if (isset($_GET['reduction'])) {
            if ($primary->reduction > 0) {
                //not over 100%
                if ((float)$primary->reduction > 1) {
                    $primary->reduction = 1;
                }

                //discount exist?
                $exists = $mysqli->query("
                    SELECT id_specific_price
                    FROM "._DB_PREFIX_."specific_price
                    WHERE id_product='".$id_product."' AND id_product_attribute='".$id_product_attribute."' AND id_specific_price_rule=0
                ");

                //then update reduction
                if ($exists->num_rows > 0) {
                    $mysqli->query("
                        UPDATE "._DB_PREFIX_."specific_price
                        SET reduction='".$primary->reduction."'
                        WHERE id_product='".$id_product."' AND id_product_attribute='".$id_product_attribute."' AND reduction != '".$primary->reduction."' AND id_specific_price_rule=0
                    ");
                }
                //or insert new reduction
                else {
                    $mysqli->query("
                        INSERT IGNORE INTO "._DB_PREFIX_."specific_price
                        (id_specific_price_rule, id_cart, id_product, id_shop, id_shop_group, id_currency, id_country, id_group, id_customer, id_product_attribute, price, from_quantity, reduction, reduction_tax, reduction_type, `from`, `to`) 
                        VALUES
                        ('0', '0', '".$id_product."', '0', '0', '0', '0', '0', '0', '".$id_product_attribute."', '-1', '1', '".$primary->reduction."', '1', 'percentage', '0000-00-00 00:00', '0000-00-00 00:00')
                    ");
                }
            }
            //no reduction? => delete
            else {
                $mysqli->query("DELETE FROM "._DB_PREFIX_."specific_price WHERE id_product='".$id_product."' AND id_product_attribute='".$id_product_attribute."' AND id_specific_price_rule=0");
            }
        }

        //update product properties?
        if (isset($_GET['properties'])) {
            $creation_date = "date_add"; //default - do nothing
            if ((string)$primary->creation_date != '0000-00-00 00:00:00') {
                $creation_date = "'".$primary->creation_date."'";
            }

            //product => update creation date, ean, dimensions, supplier id
            if ((int)$row['id_product_attribute'] === -1) {
                $mysqli->query("
                    UPDATE "._DB_PREFIX_."product 
                    SET ean13='".$primary->ean."', date_add=".$creation_date.", weight='".((float)$primary->dimensions->weight)."', width='".((float)$primary->dimensions->length)."', height='".((float)$primary->dimensions->width)."', depth='".((float)$primary->dimensions->height)."', exitshop_supplier_id='".((int)$primary->supplier_id)."' 
                    WHERE id_product='".$id_product."'
                ");
                $mysqli->query("
                    UPDATE "._DB_PREFIX_."product_shop 
                    SET date_add=".$creation_date." 
                    WHERE id_product='".$id_product."'
                ");
            }
            //attribute => update ean, dimension, supplier id
            else {
                $mysqli->query("
                    UPDATE "._DB_PREFIX_."product_attribute 
                    SET ean13='".$primary->ean."', weight='".((float)$primary->dimensions->weight)."' 
                    WHERE id_product='".$id_product."' AND id_product_attribute=".$id_product_attribute
                );
                $mysqli->query("
                    UPDATE "._DB_PREFIX_."product_attribute_shop 
                    SET weight='".((float)$primary->dimensions->weight)."' 
                    WHERE id_product='".$id_product."' AND id_product_attribute=".$id_product_attribute
                );
                //set product weight to 0 and other dimensions
                $mysqli->query("
                    UPDATE "._DB_PREFIX_."product 
                    SET weight = '0', width='".((float)$primary->dimensions->length)."', height='".((float)$primary->dimensions->width)."', depth='".((float)$primary->dimensions->height)."', date_add=".$creation_date.", exitshop_supplier_id='".((int)$primary->supplier_id)."'
                    WHERE id_product='".$id_product."'
                ");
                //update date add
                $mysqli->query("
                    UPDATE "._DB_PREFIX_."product_shop 
                    SET date_add=".$creation_date."
                    WHERE id_product='".$id_product."'
                ");
            }

            //update exitshop availability text?
            if (isset($conf['exit_availability']) && $conf['exit_availability'] && (int)$primary->supplier_id > 0) {
                foreach ($langs as $id_lang => $iso_code) {
                    $short = isset($conf['exitshop_availability_short_'.$iso_code.'_'.((int)$primary->supplier_id)]) ? $conf['exitshop_availability_short_'.$iso_code.'_'.((int)$primary->supplier_id)] : null;
                    $long = isset($conf['exitshop_availability_long_'.$iso_code.'_'.((int)$primary->supplier_id)]) ? $conf['exitshop_availability_long_'.$iso_code.'_'.((int)$primary->supplier_id)] : null;
                    $short_fake = isset($conf['exitshop_availability_short_fake_'.$iso_code.'_'.((int)$primary->supplier_id)]) ? $conf['exitshop_availability_short_fake_'.$iso_code.'_'.((int)$primary->supplier_id)] : null;
                    $long_fake = isset($conf['exitshop_availability_long_fake_'.$iso_code.'_'.((int)$primary->supplier_id)]) ? $conf['exitshop_availability_long_fake_'.$iso_code.'_'.((int)$primary->supplier_id)] : null;

                    $mysqli->query("
                        UPDATE "._DB_PREFIX_."product_lang 
                        SET 
                            exitshop_availability_short = ".($short != '' ? "'".$short."'" : "NULL").", 
                            exitshop_availability_long = ".($long != '' ? "'".$long."'" : "NULL").",
                            exitshop_availability_short_fake = ".($short_fake != '' ? "'".$short_fake."'" : "NULL").", 
                            exitshop_availability_long_fake = ".($long_fake != '' ? "'".$long_fake."'" : "NULL")."
                        WHERE id_product='".$id_product."' AND id_lang='".$id_lang."'
                    ");
                }
            }
        }
    }
}

//batch update everything -----------------------------------------------------------------
if (isset($_GET['quantity'])) {
    $mysqli->autocommit(false);
    $db_error_results = array();

    // 1 deactivate all
    $db_error_results[] = $mysqli->query("UPDATE "._DB_PREFIX_."product SET active=0");

    // 2A standard - no order out of stock - activace all > 0
    if ($conf['PS_ORDER_OUT_OF_STOCK'] == 0) {
        $db_error_results[] = $mysqli->query("
            UPDATE "._DB_PREFIX_."product p
            JOIN "._DB_PREFIX_."stock_available sa ON sa.id_product=p.id_product
            SET p.active=1, p.available_for_order=1
            WHERE p.reference IN (".implode(',', $xml_primarys).") AND sa.quantity > 0
        ");
        $db_error_results[] = $mysqli->query("
            UPDATE "._DB_PREFIX_."product p 
            SET p.active=1,p.available_for_order=1 
            WHERE id_product IN (  
                SELECT DISTINCT pa.id_product
                FROM "._DB_PREFIX_."stock_available sa 
                JOIN "._DB_PREFIX_."product_attribute pa ON pa.id_product_attribute=sa.id_product_attribute
                WHERE sa.quantity > 0 AND pa.reference IN (".implode(',', $xml_primarys).")
            )
        ");
    }
    // 2B nonstandard - yes order out of stock - activate all with > 0 OR out of stock (1 - allowed, 2 - default mainly)
    elseif ($conf['PS_ORDER_OUT_OF_STOCK'] == 1) {
        $db_error_results[] = $mysqli->query("
            UPDATE "._DB_PREFIX_."product p
            JOIN "._DB_PREFIX_."stock_available sa ON sa.id_product=p.id_product
            SET p.active=1, p.available_for_order=1
            WHERE p.reference IN (".implode(',', $xml_primarys).") AND (sa.quantity > 0 OR sa.out_of_stock > 0)
        ");
        $db_error_results[] = $mysqli->query("
            UPDATE "._DB_PREFIX_."product p 
            SET p.active=1,p.available_for_order=1 
            WHERE id_product IN (  
                SELECT DISTINCT pa.id_product
                FROM "._DB_PREFIX_."stock_available sa 
                JOIN "._DB_PREFIX_."product_attribute pa ON pa.id_product_attribute=sa.id_product_attribute
                WHERE (sa.quantity > 0 OR sa.out_of_stock > 0) AND pa.reference IN (".implode(',', $xml_primarys).")
            )
        ");
    }
    // 3 universal - apply data from ps_product to ps_product_shop
    $db_error_results[] = $mysqli->query("UPDATE "._DB_PREFIX_."product_shop SET active=0");
    $db_error_results[] = $mysqli->query("UPDATE "._DB_PREFIX_."product_shop SET active=1,available_for_order=1 WHERE id_product IN (SELECT id_product FROM "._DB_PREFIX_."product WHERE active=1)");

    //commit or rollback
    if (!in_array(false, $db_error_results)) {
        echo "<br>ok, commit";
        $mysqli->commit();
    } else {
        echo "<br>fail, rollback";
        $mysqli->rollback();
        mail(Configuration::get('PS_SHOP_EMAIL'), 'exitshop batch activator failed on'.date('d.m.Y'), '');
    }

    $mysqli->autocommit(true);

    //variant not in xml? set quantity = 0
    $mysqli->query("
        UPDATE "._DB_PREFIX_."stock_available 
        SET quantity='-999'
        WHERE id_product_attribute != 0 AND id_product_attribute IN (  
            SELECT DISTINCT pa.id_product_attribute
            FROM "._DB_PREFIX_."product_attribute pa
            WHERE pa.reference NOT IN (".implode(',', $xml_primarys).")
        )
    ");

    //sum up combinations quantities and put in stock_available (for filtering only in stock and for good feeling, since PS does this too)
    $mysqli->query("
        UPDATE "._DB_PREFIX_."stock_available sa
        JOIN (
            SELECT id_product, SUM(IF(quantity < 0 OR quantity IS NULL,0,quantity)) AS sum, SUM(IF(fake_quantity < 0 OR fake_quantity IS NULL,0,fake_quantity)) AS fake_sum 
            FROM "._DB_PREFIX_."stock_available 
            WHERE id_product_attribute!=0 GROUP BY id_product
        ) sa2 ON sa2.id_product=sa.id_product
        SET sa.quantity = sa2.sum, sa.fake_quantity = sa2.fake_sum
        WHERE sa.id_product_attribute=0
    ");
}


//automatic category 3/3 - delete old ones
if (isset($_GET['category'])) {
    if (isset($conf['exit_reference_status']) && $conf['exit_reference_status'] == 1) {
        $automatic_category_remain = implode(",", array_unique($automatic_category_remain));
        $automatic_out_of_stock_remain = implode(",", array_unique($automatic_out_of_stock_remain));
        $automatic_on_sale = implode(",", array_unique($automatic_on_sale));

        //delete from category
        $mysqli->query("DELETE FROM "._DB_PREFIX_."category_product WHERE id_category=".((int)$conf['exit_reference_category'])." AND id_product NOT IN (".$automatic_category_remain.")");
        
        //also put on_sale :)
        $mysqli->query("UPDATE "._DB_PREFIX_."product SET on_sale=0");
        $mysqli->query("UPDATE "._DB_PREFIX_."product_shop SET on_sale=0");
        $mysqli->query("UPDATE "._DB_PREFIX_."product SET on_sale=1 WHERE id_product IN (".$automatic_on_sale.")");
        $mysqli->query("UPDATE "._DB_PREFIX_."product_shop SET on_sale=1 WHERE id_product IN (".$automatic_on_sale.")");

        //activate out of stock in this category?
        if ($conf['PS_ORDER_OUT_OF_STOCK'] == 1 && $conf['exit_reference_deactivate'] == 1) {
            $mysqli->query("UPDATE "._DB_PREFIX_."stock_available SET out_of_stock=2 WHERE id_product NOT IN (".$automatic_category_remain.") AND id_product NOT IN (".$automatic_out_of_stock_remain.")");
            $mysqli->query("UPDATE "._DB_PREFIX_."product SET out_of_stock=2 WHERE id_product NOT IN (".$automatic_category_remain.") AND id_product NOT IN (".$automatic_out_of_stock_remain.")");
        }
    }
}

$mysqli->close();
echo "<br>update: ".$count.", ".round(microtime(true) - $start, 3)."s";
