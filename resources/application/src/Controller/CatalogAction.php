<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Component catalogue — the living documentation of the AdminBundle design system.
 *
 * Provided by castor-starter to the contribution environment (it is NOT shipped in the
 * bundle). It renders the bundle's catalogue template on the *real* bundle CSS — extending
 * the admin base — so it cannot drift from what the admin actually looks like. Dev only:
 * a build-time reference, never served to a production admin.
 */
class CatalogAction extends AbstractController
{
    public function __invoke(): Response
    {
        if ('dev' !== $this->getParameter('kernel.environment')) {
            throw new NotFoundHttpException();
        }

        return $this->render('@AropixelAdmin/catalog/index.html.twig');
    }
}
