all :
	composer.phar install --no-ansi --no-dev --no-interaction --no-plugins --no-progress --no-scripts --optimize-autoloader &&\
	git archive HEAD -o ./woocommerce-mastercard.zip &&\
	zip -rq ./woocommerce-mastercard.zip ./vendor &&\
	echo "\nCreated woocommerce-mastercard.zip\n"
