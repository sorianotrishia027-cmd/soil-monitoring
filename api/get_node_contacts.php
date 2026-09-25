<?php
// api/get_node_contacts.php

// Huwag munang gumamit ng database kung nagka-error 500, direktang ibalik ang numero para sa pagsubok
$phone_number = "09123456789"; // Palitan mo ng totoong numerong nais pagdalhan ng SMS

echo trim($phone_number);
exit;
?>