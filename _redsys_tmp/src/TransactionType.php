<?php
declare(strict_types=1);

namespace Redsys;

/** `Ds_Merchant_TransactionType`: what the merchant asks the terminal to do. */
enum TransactionType: string
{
    case Authorisation = '0';
    case Preauthorisation = '1';
    case PreauthorisationConfirmation = '2';
    case AutomaticRefund = '3';
    case RecurringTransaction = '5';
    case SuccessiveTransaction = '6';
    case Preauthentication = '7';
    case PreauthenticationConfirmation = '8';
    case PreauthorisationCancellation = '9';
    case DeferredAuthorisation = 'O';
    case DeferredAuthorisationConfirmation = 'P';
    case DeferredAuthorisationCancellation = 'Q';
    case DeferredFirstInstalment = 'R';
    case DeferredNextInstalment = 'S';
}
