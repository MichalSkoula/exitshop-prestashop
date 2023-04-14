<?php 
if (!defined('_PS_VERSION_')) {
    exit;
}

class Exitshop extends Module
{
    public function __construct()
    {
        $this->name = 'exitshop';
        $this->tab = 'others';
        $this->version = '1.2';
        $this->author = 'Michal Škoula';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array('min' => '1.6');
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('ExitShop synchronizace');
        $this->description = $this->l('Synchronizační modul pro Exitshop.cz');

        $this->confirmUninstall = $this->l('Opravdu chcete modul odinstalovat? Synchronizace již nebude probíhat.');

        $this->main_url = "https://www.exitshop.cz/";
        $this->db = Db::getInstance();
        $this->page_number_value = 500;

        $this->xml = false;
        $this->suppliers = array();
    }

    public function getContent()
    {
        $this->load_xml();

        $output = "";

        //save import configuration----------------------------------------------------------
        if (Tools::isSubmit('submit'.$this->name.'import')) {
            $exit_primary_url = (string)(Tools::getValue('exit_primary_url'));
            Configuration::updateValue('exit_primary_url', $exit_primary_url);
            $exit_import_new = (int)(Tools::getValue('exit_import_new'));
            Configuration::updateValue('exit_import_new', $exit_import_new);
            $exit_import_category = (int)(Tools::getValue('exit_import_category'));
            Configuration::updateValue('exit_import_category', $exit_import_category);
            $exit_update_old = (int)(Tools::getValue('exit_update_old'));
            Configuration::updateValue('exit_update_old', $exit_update_old);
        }
        //save orders configuration----------------------------------------------------------------
        elseif (Tools::isSubmit('submit'.$this->name.'orders')) {
            $exit_allow_xml = (int)(Tools::getValue('exit_allow_xml'));
            Configuration::updateValue('exit_allow_xml', $exit_allow_xml);
        }
        //save visitors configuration----------------------------------------------------------------
        elseif (Tools::isSubmit('submit'.$this->name.'visitors')) {
            $exit_visitors = (int)(Tools::getValue('exit_visitors'));
            Configuration::updateValue('exit_visitors', $exit_visitors);

            $shops = $this->db->ExecuteS("
	            SELECT s.id_shop,s.name
	            FROM "._DB_PREFIX_."shop s
	            WHERE s.active=1
	        ");

            foreach ($shops as $shop) {
                Configuration::updateValue('exit_visitors_shop_id_'.$shop['id_shop'], (int)(Tools::getValue('exit_visitors_shop_id_'.$shop['id_shop'])));
            }
        }
        //save automatic category configuration----------------------------------------------------------
        elseif (Tools::isSubmit('submit'.$this->name.'automaticcategory')) {
            $exit_reference = (string)(Tools::getValue('exit_reference'));
            Configuration::updateValue('exit_reference', $exit_reference);
            $exit_reference2 = (string)(Tools::getValue('exit_reference2'));
            Configuration::updateValue('exit_reference2', $exit_reference2);
            $exit_reference_category = (int)(Tools::getValue('exit_reference_category'));
            Configuration::updateValue('exit_reference_category', $exit_reference_category);
            $exit_reference_status = (int)(Tools::getValue('exit_reference_status'));
            Configuration::updateValue('exit_reference_status', $exit_reference_status);
            $exit_reference_deactivate = (int)(Tools::getValue('exit_reference_deactivate'));
            Configuration::updateValue('exit_reference_deactivate', $exit_reference_deactivate);
        }
        //load langs from xml---------------------------------------------------------------------------
        elseif (Tools::isSubmit('submit'.$this->name.'xml_langs')) {
            if ($this->xml !== false) {
                //load languages
                $languages = $this->db->ExecuteS("
    				SELECT * 
    				FROM "._DB_PREFIX_."lang l 
    				JOIN "._DB_PREFIX_."lang_shop ls ON ls.id_lang=l.id_lang
    			");
                $supplier_to_process = (int)Tools::getValue('exit_supplier');
                if ($supplier_to_process > 0) {
                    //fetch all products and variants with reference
                    $ps_products = array();
                    $query = $this->db->ExecuteS("
						SELECT reference, id_product
						FROM "._DB_PREFIX_."product_attribute
						WHERE reference IS NOT NULL AND reference != ''

						UNION

						SELECT reference, id_product
						FROM "._DB_PREFIX_."product
						WHERE reference IS NOT NULL AND reference != ''
					");
                    foreach ($query as $row2) {
                        $ps_products[(int)$row2['reference']] = $row2;
                    }

                    //process xml
                    foreach ($this->xml as $primary) {
                        //only selected supplier
                        if ($supplier_to_process != (int)$primary->supplier_id) {
                            continue;
                        }
                        //foreach language variants
                        foreach ($primary->products->product as $product) {
                            foreach ($languages as $row) {
                                if ($row['iso_code'] == (string)$product->country && (bool)Tools::getValue('exit_lang_'.$row['iso_code']) && isset($ps_products[(int)$primary->primary_id])) {
                                    $this->db->Execute(
                                        "
										UPDATE "._DB_PREFIX_."product_lang pl
										SET name = '".pSQL($product->name)."', description = '".pSQL($product->description_long, true)."', description_short = '".pSQL($product->description, true)."'
										WHERE pl.id_product = ".$ps_products[(int)$primary->primary_id]['id_product']." AND pl.id_lang=".$row['id_lang']
                                    );
                                }
                            }
                        }
                    }
                }
            }
        }
        //check if exists--------------------------------------------------------------------------
        elseif (Tools::isSubmit('submit'.$this->name.'check')) {
            $output .= "<div class='panel'><strong>ID mateřských produktů, které chybí v PS:</strong><br> <table class='table'>";
            if ($this->xml !== false) {
                //load all references from DB
                $references = array();
                $result = $this->db->ExecuteS("
					SELECT DISTINCT reference FROM(
						SELECT reference
						FROM "._DB_PREFIX_."product_attribute
						WHERE reference IS NOT NULL

						UNION

						SELECT reference
						FROM "._DB_PREFIX_."product
						WHERE reference IS NOT NULL
					) tbl
				");
                foreach ($result as $row) {
                    $references[] = (int)$row['reference'];
                }

                //process xml
                $i = 0;
                foreach ($this->xml as $primary) {
                    if ($primary->quantity <= 0) {
                        continue;
                    }
                    if (!in_array((int)$primary->primary_id, $references) && (int)$primary->supplier_id != 1171 && (int)$primary->supplier_id != 1188) {
                        $output .= "
	    					<tr>
	    						<td>".(int)$primary->primary_id."</td>
	    						<td><a target='_blank' href='https://www.exitshop.cz/uzivatele/primary_upravit/".(int)$primary->primary_id."'>
	    							".(string)$primary->products->product[0]->name."
	    							</a>
	    						</td>
	    					</tr>";
                        $i++;
                    }
                }
            }
            $output .= "</table><br>celkem: ".$i."</div>";
        }
        //check pairing-----------------------------------------------------------------------------------
        elseif (Tools::isSubmit('submit'.$this->name.'check2')) {
            $output .= "<div class='panel'>
	    					<table class='table'> 
	    					<thead>
	    						<tr><th>ID</th><th>ES produkt</th><th>PS produkt</th></tr>
	    					</thead>
	    					<tbody>";
            
            $page_number = (int)Tools::getValue('exit_page_number');
            if ($page_number <= 1) {
                $page_number = 1;
            }

            $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');
            $token_link = "index.php?controller=AdminProducts&token=".Tools::getAdminTokenLite('AdminProducts')."&updateproduct&id_product=";
            $link = new LinkCore;
            
            //process xml
            if ($this->xml !== false) {
                $i = 0;
                foreach ($this->xml as $primary) {
                    //paging
                    $i++;
                    if ($i < ($page_number-1) * $this->page_number_value) {
                        continue;
                    }
                    if ($i > $page_number * $this->page_number_value) {
                        break;
                    }

                    $primary_id = (int)$primary->primary_id;
                    $ps_product = "";
                    $image_link = "";

                    //find it in DB
                    $result = $this->db->ExecuteS("
		    			SELECT reference,id_product,id_product_attribute
						FROM "._DB_PREFIX_."product_attribute
						WHERE reference=".$primary_id."

						UNION

						SELECT reference,id_product,'-1' AS id_product_attribute
						FROM "._DB_PREFIX_."product
						WHERE reference=".$primary_id."
					");
                    foreach ($result as $row) {
                        //product
                        if ((int)$row['id_product_attribute'] === -1) {
                            $result2 = $this->db->getRow("
	    						SELECT name,id_product,link_rewrite, NULL AS id_product_attribute
	    						FROM "._DB_PREFIX_."product_lang
	    						WHERE id_product=".((int)$row['id_product'])." AND id_lang=".$default_lang." AND LENGTH(name) > 0
    						");
                        }
                        //variant
                        else {
                            $result2 = $this->db->getRow("
	    						SELECT pa.id_product,pa.id_product_attribute,pl.link_rewrite,pl.name
	    						FROM "._DB_PREFIX_."product_attribute pa
	    						JOIN "._DB_PREFIX_."product_lang pl ON pl.id_product=pa.id_product
	    						WHERE 
	    							pa.id_product=".((int)$row['id_product'])." 
	    							AND pa.id_product_attribute=".((int)$row['id_product_attribute'])."
	    							AND pl.id_lang=".$default_lang." 
	    							AND LENGTH(pl.name) > 0
    						");
                        }

                        if ($result2) {
                            $images = Image::getImages($this->context->language->id, $result2['id_product'], $result2['id_product_attribute']);
                            if (is_array($images)) {
                                foreach ($images as $k => $image) {
                                    $image_link = $this->context->link->getImageLink($result2['link_rewrite'], $result2['id_product'].'-'.$image['id_image'], ImageType::getFormatedName('small'));
                                    break;
                                }
                            }
                            $ps_product .= "
								<a target='_blank' href='".$token_link.$result2['id_product']."'>
									".$result2['name']."
								</a><br>
								<img height='125' src='".$image_link."'>
							";
                        }
                    }

                    $output .= "
    					<tr>
    						<td><big>".$primary_id."</big></td>
    						<td>
    							<a target='_blank' href='https://www.exitshop.cz/uzivatele/primary_upravit/".$primary_id."'>
    								".(string)$primary->products->product[0]->name."
    							</a>
    							<br>
    							<img height='125' src='".(string)$primary->thumbnail."'>
    						</td>
    						<td>
    							".$ps_product."
    						</td>
    					</tr>
    				";
                }
            }

            $output .= "</tbody></table></div>";
        }
        // save availability -----------------------------------------------------------
        elseif (Tools::isSubmit('submit'.$this->name.'availability')) {
            $exit_availability = (int)(Tools::getValue('exit_availability'));
            Configuration::updateValue('exit_availability', $exit_availability);

            $langs = $this->db->ExecuteS("
	            SELECT l.iso_code, l.name
	            FROM "._DB_PREFIX_."lang l
	            WHERE l.active=1
	        ");
            
            foreach ($this->suppliers as $supplier_id => $supplier_name) {
                foreach ($langs as $lang) {
                    Configuration::updateValue('exitshop_availability_short_'.$lang['iso_code'].'_'.$supplier_id, Tools::getValue('exitshop_availability_short_'.$lang['iso_code'].'_'.$supplier_id));
                    Configuration::updateValue('exitshop_availability_long_'.$lang['iso_code'].'_'.$supplier_id, Tools::getValue('exitshop_availability_long_'.$lang['iso_code'].'_'.$supplier_id));
                    Configuration::updateValue('exitshop_availability_short_fake_'.$lang['iso_code'].'_'.$supplier_id, Tools::getValue('exitshop_availability_short_fake_'.$lang['iso_code'].'_'.$supplier_id));
                    Configuration::updateValue('exitshop_availability_long_fake_'.$lang['iso_code'].'_'.$supplier_id, Tools::getValue('exitshop_availability_long_fake_'.$lang['iso_code'].'_'.$supplier_id));
                }
            }
        }
        // end savings

        $output .= "<div class='panel'><big>Modul slouží pro synchronizaci s ExitShopem. <strong>Nastavte si nahoře \"Všechny obchody\".</strong></big></div>";

        $output .= "<div>".$this->displayFormImport()."</div>";
        $output .= "<div>".$this->displayFormCheck()."</div>";
        $output .= "<div>".$this->displayFormCheck2()."</div>";
        $output .= "<div>".$this->displayFormOrders()."</div>";
        $output .= "<div>".$this->displayFormXmlLangs()."</div>";
        $output .= "<div>".$this->displayFormAutomaticCategory()."</div>";
        $output .= "<div>".$this->displayFormVisitors()."</div>";
        $output .= "<div>".$this->displayFormExitShopAvailability()."</div>";
        $output .= "
	    	<div class='panel'>
	    		Další skripty, které nabízíme. Napište nám na info@exitshop.cz 
	    		<ul>
	    			<li>Kontrola duplicitního párování</li>
	    			<li>Přenos prodejů z ExitShopu</li>
	    			<li>Automatické zařazení produktů do nadřazených kategorií</li>
	    			<li>Automatické nastavení výchozí varianty (nejlevnější)</li>
	    			<li>Úprava zahraničního DPH podle českého vzoru</li>
	    		</ul>
    		</div>
    	";
        
        return $output;
    }

    public function displayFormImport()
    {
        // Get default language
        $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');

        $helper = new HelperForm();
         
        $inputy = array(
            array(
                'type' => 'text',
                'label' => $this->l('Adresa XML mateřských produktů z ExitShopu'),
                'name' => 'exit_primary_url',
                'desc' => "Cron aktualizace: <a target='_blank' href='".Tools::getHttpHost(true).__PS_BASE_URI__."modules/exitshop/cron.php?update&reduction'>".Tools::getHttpHost(true).__PS_BASE_URI__."modules/exitshop/cron.php?update&reduction</a>"
            ),
            array(
                'type' => 'hidden', //switch
                'label' => $this->l('Importovat nové produkty? (JEŠTĚ NEFUNGUJE)'),
                'name' => 'exit_import_new',
                'is_bool' => true,
                'values' => array(
                    array(
                        'id' => 'active_on',
                        'value' => 1,
                        'label' => $this->l('Zapnuto'),
                    ),
                    array(
                        'id' => 'active_off',
                        'value' => 0,
                        'label' => $this->l('Vypnuto')
                    )
                ),
            ),
            array(
                'type' => 'hidden', //text
                'label' => $this->l('ID kategorie pro import nových produktů'),
                'name' => 'exit_import_category',
                'desc' => ""
            ),
            array(
                'type' => 'switch',
                'label' => $this->l('Aktualizovat produkty?'),
                'name' => 'exit_update_old',
                'desc' => '',
                'is_bool' => true,
                'values' => array(
                    array(
                        'id' => 'active_on',
                        'value' => 1,
                        'label' => $this->l('Zapnuto'),
                    ),
                    array(
                        'id' => 'active_off',
                        'value' => 0,
                        'label' => $this->l('Vypnuto')
                    )
                ),
            )
        );

        // Init Fields form array
        $fields_form[0]['form'] = array(
            'legend' => array(
                'title' => $this->l('1. Nastavení aktualizace produktů'),
            ),
            'input' => $inputy,
            'submit' => array(
                'title' => $this->l('Uložit'),
                'class' => 'button'
            )
        );
         
        // Module, token and currentIndex
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
         
        // Language
        $helper->default_form_language = $helper->allow_employee_form_lang = $default_lang;
         
        // Title and toolbar
        $helper->title = $this->displayName;
        $helper->submit_action = 'submit'.$this->name.'import';
         
        // Load current value
        $helper->fields_value['exit_primary_url'] = Configuration::get('exit_primary_url');
        $helper->fields_value['exit_import_new'] = Configuration::get('exit_import_new');
        $helper->fields_value['exit_update_old'] = Configuration::get('exit_update_old');
        $helper->fields_value['exit_import_category'] = Configuration::get('exit_import_category');
         
        return $helper->generateForm($fields_form);
    }


    public function displayFormCheck()
    {
        $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');
        $helper = new HelperForm();
        $inputy = array();

        // Init Fields form array
        $fields_form[0]['form'] = array(
            'legend' => array(
                'title' => $this->l('2. Které mateřské produkty ještě chybí?')
            ),
            'desc' => "<p>Zjistí, které mateřské produkty ještě nemáte napárované do Prestashopu. Jen aktivní a skladem. Musí být zadáno XML z ExitShopu.</p>",
            'input' => $inputy,
            'submit' => array(
                'title' => $this->l('Zkontroluj'),
                'class' => 'button'
            )
        );

        // Module, token and currentIndex
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
         
        // Language
        $helper->default_form_language = $helper->allow_employee_form_lang = $default_lang;
         
        // Title and toolbar
        $helper->title = $this->displayName;
        $helper->submit_action = 'submit'.$this->name.'check';

         
        return $helper->generateForm($fields_form);
    }

    public function displayFormCheck2()
    {
        $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');
        $helper = new HelperForm();
        $inputy = array();

        $inputy[] = array(
            'type' => 'text',
            'label' => $this->l('Číslo stránky'),
            'name' => 'exit_page_number',
            'desc' => "Na jedné stránce je ".$this->page_number_value." produktů. Zadejte číslo stránky."
        );
        // Init Fields form array
        $fields_form[0]['form'] = array(
            'legend' => array(
                'title' => $this->l('3. Zkontroluj napárování')
            ),
            'desc' => "<p>Vizuální kontrola správnosti napárování</p>",
            'input' => $inputy,
            'submit' => array(
                'title' => $this->l('Zkontroluj'),
                'class' => 'button'
            )
        );
        $helper->fields_value['exit_page_number'] = 1;

        // Module, token and currentIndex
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
         
        // Language
        $helper->default_form_language = $helper->allow_employee_form_lang = $default_lang;
         
        // Title and toolbar
        $helper->title = $this->displayName;
        $helper->submit_action = 'submit'.$this->name.'check2';
         
        return $helper->generateForm($fields_form);
    }

    public function displayFormOrders()
    {
        // Get default language
        $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');

        $helper = new HelperForm();
         
        $inputy = array(
            array(
                'type' => 'switch',
                'label' => $this->l('Povolit XML pro přenos objednávek?'),
                'name' => 'exit_allow_xml',
                'is_bool' => true,
                'desc' => "Zadá se v ExitShopu: <a target='_blank' href='".Tools::getHttpHost(true).__PS_BASE_URI__."modules/exitshop/xml.php?country=cs'>".Tools::getHttpHost(true).__PS_BASE_URI__."modules/exitshop/xml.php?country=cs</a> (pro jiné země vyměníte poslední parametr za například sk)",
                'values' => array(
                    array(
                        'id' => 'active_on',
                        'value' => 1,
                        'label' => $this->l('Zapnuto'),
                    ),
                    array(
                        'id' => 'active_off',
                        'value' => 0,
                        'label' => $this->l('Vypnuto')
                    )
                ),
            ),
        );

        // Init Fields form array
        $fields_form[0]['form'] = array(
            'legend' => array(
                'title' => $this->l('4. Nastavení přenosu objednávek do ExitShopu')
            ),
            'input' => $inputy,
            'submit' => array(
                'title' => $this->l('Uložit'),
                'class' => 'button'
            )
        );
         
        // Module, token and currentIndex
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
         
        // Language
        $helper->default_form_language = $helper->allow_employee_form_lang = $default_lang;
         
        // Title and toolbar
        $helper->title = $this->displayName;
        $helper->submit_action = 'submit'.$this->name.'orders';
         
        // Load current value
        $helper->fields_value['exit_allow_xml'] = Configuration::get('exit_allow_xml');
         
        return $helper->generateForm($fields_form);
    }

    public function displayFormXmlLangs()
    {
        $this->load_xml();

        // Get default language
        $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');
        $helper = new HelperForm();
         
        $inputy = array();
        //load languages
        $languages = $this->db->ExecuteS("
			SELECT * 
			FROM "._DB_PREFIX_."lang l 
			JOIN "._DB_PREFIX_."lang_shop ls ON ls.id_lang=l.id_lang
			WHERE active=1
		");
        foreach ($languages as $row) {
            $inputy[] = array(
                'type' => 'switch',
                'label' => $this->l($row['name']." (".$row['iso_code'].")"),
                'name' => 'exit_lang_'.$row['iso_code'],
                'is_bool' => true,
                'values' => array(
                    array(
                        'id' => 'active_on',
                        'value' => 1,
                        'label' => $this->l('Ano'),
                    ),
                    array(
                        'id' => 'active_off',
                        'value' => 0,
                        'label' => $this->l('Ne')
                    )
                )
            );
            $helper->fields_value['exit_lang_'.$row['iso_code']] = 0;
        }

        //suppliers
        $options_suppliers = array();
        foreach ($this->suppliers as $sup_id => $sup_name) {
            $options_suppliers[] = array(
                'exit_supplier' => $sup_id,
                'name' => $sup_name
            );
        }
        $inputy[] = array(
            'type' => 'select',
            'label' => 'Dodavatel z ExitShopu',
            'name' => 'exit_supplier',
            'options' => array(
                'query' => $options_suppliers,
                'id' => 'exit_supplier',
                'name' => 'name'
            ),
        );

        // Init Fields form array
        $fields_form[0]['form'] = array(
            'legend' => array(
                'title' => $this->l('5. Jednorázově nahrát popisky produktů z ExitShop XML')
            ),
            'desc' => "<p>Jednorázově nahrát popisky a překlady mateřských produktů z ExitShop XML do příslušných jazykových variant eshopu. Samozřejmě musí být zadáno XML z ExitShopu.</p>",
            'input' => $inputy,
            'submit' => array(
                'title' => $this->l('Nahrát'),
                'class' => 'button'
            )
        );

        // Module, token and currentIndex
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
         
        // Language
        $helper->default_form_language = $helper->allow_employee_form_lang = $default_lang;
         
        // Title and toolbar
        $helper->title = $this->displayName;
        $helper->submit_action = 'submit'.$this->name.'xml_langs';
         
        return $helper->generateForm($fields_form);
    }

    public function displayFormAutomaticCategory()
    {
        // Get default language
        $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');

        $helper = new HelperForm();
         
        $inputy = array(

            array(
                'type' => 'text',
                'label' => $this->l('Název dodavatele (pro zákaz objednání do mínusu + pro přidání do kategorie)'),
                'desc' => 'Můžete zadat více názvů oddělených středníkem ;',
                'name' => 'exit_reference'
            ),
            array(
                'type' => 'text',
                'label' => $this->l('ID kategorie'),
                'name' => 'exit_reference_category',
            ),
            array(
                'type' => 'text',
                'label' => $this->l('Název dodavatele (pro zákaz objednání do mínusu)'),
                'desc' => 'Můžete zadat více názvů oddělených středníkem ;',
                'name' => 'exit_reference2'
            ),
            array(
                'type' => 'switch',
                'label' => $this->l('Povolit automatické přiřazení do kategorie?'),
                'name' => 'exit_reference_status',
                'is_bool' => true,
                'values' => array(
                    array(
                        'id' => 'active_on',
                        'value' => 1,
                        'label' => $this->l('Zapnuto'),
                    ),
                    array(
                        'id' => 'active_off',
                        'value' => 0,
                        'label' => $this->l('Vypnuto')
                    )
                ),
            ),
            array(
                'type' => 'switch',
                'label' => $this->l('Deaktivovat vyprodané produkty v této kategorii (např. pokud se používá jako výprodej? + označí tyto produkty jako výprodej'),
                'name' => 'exit_reference_deactivate',
                'is_bool' => true,
                'values' => array(
                    array(
                        'id' => 'active_on',
                        'value' => 1,
                        'label' => $this->l('Zapnuto'),
                    ),
                    array(
                        'id' => 'active_off',
                        'value' => 0,
                        'label' => $this->l('Vypnuto')
                    )
                ),
            ),
        );

        // Init Fields form array
        $fields_form[0]['form'] = array(
            'legend' => array(
                'title' => $this->l('6. Automatické přiřazení do kategorie (jednostranné)'),
            ),
            'input' => $inputy,
            'submit' => array(
                'title' => $this->l('Uložit'),
                'class' => 'button'
            )
        );
         
        // Module, token and currentIndex
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
         
        // Language
        $helper->default_form_language = $helper->allow_employee_form_lang = $default_lang;
         
        // Title and toolbar
        $helper->title = $this->displayName;
        $helper->submit_action = 'submit'.$this->name.'automaticcategory';
         
        // Load current value
        $helper->fields_value['exit_reference'] = Configuration::get('exit_reference');
        $helper->fields_value['exit_reference2'] = Configuration::get('exit_reference2');
        $helper->fields_value['exit_reference_category'] = Configuration::get('exit_reference_category');
        $helper->fields_value['exit_reference_status'] = Configuration::get('exit_reference_status');
        $helper->fields_value['exit_reference_deactivate'] = Configuration::get('exit_reference_deactivate');
         
        return $helper->generateForm($fields_form);
    }

    public function displayFormExitShopAvailability()
    {
        $this->load_xml();
        
        // Get default language
        $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');
        
        $helper = new HelperForm();
        $inputy = array(
            array(
                'type' => 'switch',
                'label' => $this->l('Povolit nastavení textů dostupnosti z ExitShopu'),
                'name' => 'exit_availability',
                'desc' => 'Funkce vyžaduje další úpravy šablony',
                'is_bool' => true,
                'values' => array(
                    array(
                        'id' => 'active_on',
                        'value' => 1,
                        'label' => $this->l('Zapnuto'),
                    ),
                    array(
                        'id' => 'active_off',
                        'value' => 0,
                        'label' => $this->l('Vypnuto')
                    )
                ),
            )
        );

        $langs = $this->db->ExecuteS("
            SELECT l.iso_code, l.name
            FROM "._DB_PREFIX_."lang l
            WHERE l.active=1
        ");
        foreach ($this->suppliers as $supplier_id => $supplier_name) {
            $inputy[] = array(
                'type' => 'free',
                'label' => "<b><big>".$supplier_name."</big></b>",
                'name' => 'nic'
            );
            foreach ($langs as $lang) {
                $inputy[] = array(
                    'type' => 'text',
                    'label' => "<b>".strtoupper($lang['iso_code'])."</b>".' krátký',
                    'name' => 'exitshop_availability_short_'.$lang['iso_code'].'_'.$supplier_id
                );
                $inputy[] = array(
                    'type' => 'text',
                    'label' => "<b>".strtoupper($lang['iso_code'])."</b>".' krátký fake',
                    'name' => 'exitshop_availability_short_fake_'.$lang['iso_code'].'_'.$supplier_id
                );
                $inputy[] = array(
                    'type' => 'text',
                    'label' => "<b>".strtoupper($lang['iso_code'])."</b>".' dlouhý',
                    'name' => 'exitshop_availability_long_'.$lang['iso_code'].'_'.$supplier_id
                );
                $inputy[] = array(
                    'type' => 'text',
                    'label' => "<b>".strtoupper($lang['iso_code'])."</b>".' dlouhý fake',
                    'name' => 'exitshop_availability_long_fake_'.$lang['iso_code'].'_'.$supplier_id
                );
            }
        }

        // Init Fields form array
        $fields_form[0]['form'] = array(
            'legend' => array(
                'title' => $this->l('8. [vyžaduje úpravy šablony] Nastavení textu dostupností produktů podle dodavatelů z exitshopu'),
            ),
            'input' => $inputy,
            'submit' => array(
                'title' => $this->l('Uložit'),
                'class' => 'button'
            )
        );
         
        // Module, token and currentIndex
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
         
        // Language
        $helper->default_form_language = $helper->allow_employee_form_lang = $default_lang;
         
        // Title and toolbar
        $helper->title = $this->displayName;
        $helper->submit_action = 'submit'.$this->name.'availability';
         
        // Load current value
        $helper->fields_value['nic'] = "";
        $helper->fields_value['exit_availability'] = Configuration::get('exit_availability');
        foreach ($this->suppliers as $supplier_id => $supplier_name) {
            foreach ($langs as $lang) {
                $helper->fields_value['exitshop_availability_short_'.$lang['iso_code'].'_'.$supplier_id] = Configuration::get('exitshop_availability_short_'.$lang['iso_code'].'_'.$supplier_id);
                $helper->fields_value['exitshop_availability_long_'.$lang['iso_code'].'_'.$supplier_id] = Configuration::get('exitshop_availability_long_'.$lang['iso_code'].'_'.$supplier_id);
                $helper->fields_value['exitshop_availability_short_fake_'.$lang['iso_code'].'_'.$supplier_id] = Configuration::get('exitshop_availability_short_fake_'.$lang['iso_code'].'_'.$supplier_id);
                $helper->fields_value['exitshop_availability_long_fake_'.$lang['iso_code'].'_'.$supplier_id] = Configuration::get('exitshop_availability_long_fake_'.$lang['iso_code'].'_'.$supplier_id);
            }
        }
         
        return $helper->generateForm($fields_form);
    }

    public function displayFormVisitors()
    {
        // Get default language
        $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');

        $helper = new HelperForm();
         
        $inputy = array(
            array(
                'type' => 'switch',
                'label' => $this->l('Povolit měření návštěvnost a přenos do ExitShop statistik'),
                'name' => 'exit_visitors',
                'desc' => 'V ExitShopu musí mít daný eshop povolen přístup i bez domény (ve výchozím nastavení je to ok)',
                'is_bool' => true,
                'values' => array(
                    array(
                        'id' => 'active_on',
                        'value' => 1,
                        'label' => $this->l('Zapnuto'),
                    ),
                    array(
                        'id' => 'active_off',
                        'value' => 0,
                        'label' => $this->l('Vypnuto')
                    )
                ),
            ),
        );

        $shops = $this->db->ExecuteS("
            SELECT s.id_shop,s.name
            FROM "._DB_PREFIX_."shop s
            WHERE s.active=1
        ");

        foreach ($shops as $shop) {
            $inputy[] = array(
                'type' => 'text',
                'label' => $this->l($shop['name'].' - ES eshop ID'),
                'name' => 'exit_visitors_shop_id_'.$shop['id_shop']
            );
        }


        // Init Fields form array
        $fields_form[0]['form'] = array(
            'legend' => array(
                'title' => $this->l('7. Měřit návštěvnost'),
            ),
            'input' => $inputy,
            'submit' => array(
                'title' => $this->l('Uložit'),
                'class' => 'button'
            )
        );
         
        // Module, token and currentIndex
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
         
        // Language
        $helper->default_form_language = $helper->allow_employee_form_lang = $default_lang;
         
        // Title and toolbar
        $helper->title = $this->displayName;
        $helper->submit_action = 'submit'.$this->name.'visitors';
         
        // Load current value
        $helper->fields_value['exit_visitors'] = Configuration::get('exit_visitors');
        foreach ($shops as $shop) {
            $helper->fields_value['exit_visitors_shop_id_'.$shop['id_shop']] = Configuration::get('exit_visitors_shop_id_'.$shop['id_shop']);
        }
         
        return $helper->generateForm($fields_form);
    }

    public function hookDisplayFooter($params)
    {
        if (isset($this->context->shop->id) && Configuration::get('exit_visitors')) {
            $shop_id = Configuration::get('exit_visitors_shop_id_'.$this->context->shop->id);
            if ($shop_id) {
                $this->smarty->assign('url', $this->main_url."shops/".$shop_id."/log/user");
                return $this->display(__file__, 'footer_visitors.tpl');
            }
        }
    }

    public function install()
    {
        if (Shop::isFeatureActive()) {
            Shop::setContext(Shop::CONTEXT_ALL);
        }
        if (!parent::install()) {
            return false;
        }

        if (
            !$this->registerHook('displayFooter')
        ) {
            return false;
        }
        return true;
    }

    public function uninstall()
    {
        if (!parent::uninstall()) {
            return false;
        }

        return true;
    }

    public function toCSV($data, $filename, $sep = "\t", $apo = '"')
    {
        $eol  = "\n";

        $csv  =  '';
        $j = 0;
        $i = 0;
        $y = 1;
        $length = count($data);

        $zipname = $filename.'.zip';
        $zip = new ZipArchive;
        $zip->open($zipname, ZipArchive::CREATE);

        foreach ($data as $line) {
            $i++;
            $j++;
            if ($i == 500 || $j == $length) {
                $csv .= $apo. implode($apo.$sep.$apo, $line).$apo.$eol;
                $zip->addFromString($filename.'_'.$y.'.csv', $csv);
                //reset
                $csv  =  '';
                $i = 0;
                $y++;
            }
            $csv .= $apo. implode($apo.$sep.$apo, $line).$apo.$eol;
        }

        $zip->close();

        header('Content-Type: application/zip');
        header('Content-disposition: attachment; filename='.$zipname);
        header('Content-Length: ' . filesize($zipname));
        readfile($zipname);
    }

    private function load_xml()
    {
        //get XML
        if (file_exists('../modules/exitshop/feed.xml')) { //cached
            $this->xml = simplexml_load_file('../modules/exitshop/feed.xml');
        } elseif (Configuration::get('exit_primary_url')) { //online
            $this->xml = simplexml_load_file(Configuration::get('exit_primary_url'));
        }

        //extract suppliers
        if ($this->xml !== false) {
            foreach ($this->xml as $primary) {
                if ((int)$primary->supplier_id > 0) {
                    $this->suppliers[(int)$primary->supplier_id] = (string)$primary->supplier;
                }
            }
        }
    }
}
