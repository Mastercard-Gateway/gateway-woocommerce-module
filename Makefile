all :
	composer.phar install -o --no-dev &&\
	git archive HEAD -o ./woocommerce-mastercard.zip &&\
	zip -rq ./woocommerce-mastercard.zip ./vendor &&\
	echo "\nCreated woocommerce-mastercard.zip\n"
