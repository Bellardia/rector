<?php

namespace RectorPrefix20210804;

if (\class_exists('Tx_Extbase_Validation_Validator_NotEmptyValidator')) {
    return;
}
class Tx_Extbase_Validation_Validator_NotEmptyValidator
{
}
\class_alias('Tx_Extbase_Validation_Validator_NotEmptyValidator', 'Tx_Extbase_Validation_Validator_NotEmptyValidator', \false);
