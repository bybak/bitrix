Site configuration was moved to /local/php_interface/
(dbconn.php, init.php, after_connect*, include/).

Bitrix loads these files automatically via getLocalPath() when the /local/ directory exists.

Do not delete this folder: some checks expect /bitrix/php_interface to exist.
