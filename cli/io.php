#!/usr/bin/env php
<?php

fwrite(STDERR, "cli/io.php has been replaced by cli/sync.php\n");
fwrite(STDERR, "Dry run:  php cli/sync.php\n");
fwrite(STDERR, "Apply:    php cli/sync.php --write\n");
exit(1);
