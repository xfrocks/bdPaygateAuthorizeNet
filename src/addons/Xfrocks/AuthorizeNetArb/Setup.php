<?php

namespace Xfrocks\AuthorizeNetArb;

use XF\AddOn\AbstractSetup;
use XF\AddOn\StepRunnerInstallTrait;
use XF\AddOn\StepRunnerUninstallTrait;
use XF\AddOn\StepRunnerUpgradeTrait;
use XF\Entity\PaymentProvider;
use XF\Mvc\Entity\Finder;

class Setup extends AbstractSetup
{
    use StepRunnerInstallTrait;
    use StepRunnerUpgradeTrait;
    use StepRunnerUninstallTrait;

    public function installStep1()
    {
        /** @var Finder $finder */
        $finder = $this->app->finder('XF:PaymentProvider');
        $providers = $finder->where('addon_id', $this->addOn->getAddOnId())->fetch()->toArray();
        if (count($providers) > 0) {
            return;
        }

        /** @var PaymentProvider $provider */
        $provider = $this->app->em()->create('XF:PaymentProvider');
        $provider->bulkSet([
            'provider_id' => 'authorizenet',
            'provider_class' => 'Xfrocks\\AuthorizeNetArb:Provider',
            'addon_id' => $this->addOn->getAddOnId(),
        ]);

        $provider->save();
    }
}
