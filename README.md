# Exitshop - Prestashop

Propojovací plugin. Pro Prestashop (PS) 1.6+. Na jeho základu si můžete Exitshop propojit i s jinými systémy. Propojení je funkční, ale dále jej nevyvíjíme.

1. V ES si vytvoříte e-shop typu Prestashop a k němu propojení zde: https://www.exitshop.cz/shop_external/prestashops
2. Do PS si nahrajete tento plugin (modules/exitshop) a v administraci jej nainstalujete. Vyplníte v něm XML adresu skladu kterou získáte v bodu 1.
3. V ES si vyplníte XML adresu objednávek, které získáte v PS modulu.
4. V ES e-shopu si také vytvoříte odpovídající dopravce a v nastavení propojení je propárujete s těmi v PS.
4. Nastavíte si cron na vašem serveru:

## cron.php

Záleží co a jak často chcete synchronizovat produkty ES -> PS

/modules/exitshop/cron.php?category&quantity&price&reduction&properties

## xml.php

XML s objednávkami, ze kterého si ES stahuje objednávky PS -> ES

/modules/exitshop/xml.php?country=cs

Příklad vygenerovaného XML: https://www.exitshop.cz/assets2/other_files/prestashop-example.xml

