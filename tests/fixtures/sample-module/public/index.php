<?php

declare(strict_types=1);

use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Database\QueryUtils;
use OpenHealthcare\Common\OpenEmr;

if (!AclMain::aclCheckCore('patients', 'med', '', ['write', 'addonly'])) {
    OpenEmr::deny('refused', xl('Sample'));
}
OpenEmr::audit('patient-record-select', 'Sample: viewed');
$rows = QueryUtils::fetchRecords('SELECT `pid`, `fname` FROM `patient_data` WHERE `pid` = ?', [1]);
QueryUtils::sqlInsert('INSERT INTO `sample_thing` (`pid`) VALUES (?)', [1]);
$fee = QueryUtils::fetchRecords('SELECT `pid` FROM `sliding_fee_assessment` WHERE `pid` = ?', [1]);
QueryUtils::sqlInsert('INSERT INTO `staff_worklist_log` (`pid`) VALUES (?)', [1]);
file_put_contents('/tmp/sample.txt', 'x');
echo text(xl('Done'));
