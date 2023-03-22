<?php
echo base64_encode( password_hash( $argv[1], PASSWORD_DEFAULT ) ) . PHP_EOL;
