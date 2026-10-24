<?php

namespace App\Enums;

/**
 * Application capabilities enforced through Laravel Gates. Roles map to
 * capabilities in config/dam.php; requests can never grant themselves one.
 */
enum Capability: string
{
    case ViewDashboard = 'media.view_dashboard';
    case Upload = 'media.upload';
    case Edit = 'media.edit';
    case Replace = 'media.replace';
    case Delete = 'media.delete';
    case Submit = 'media.submit';
    case Review = 'media.review';
    case Approve = 'media.approve';
    case Publish = 'media.publish';
    case Unpublish = 'media.unpublish';
    case ManageStorage = 'media.manage_storage';
    case ManageSettings = 'media.manage_settings';
    case ViewAudit = 'media.view_audit';
}
