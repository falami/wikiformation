<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\{BinaryFileResponse, Request};
use Symfony\Component\Routing\Attribute\Route;

/** Serve the recruitment bundle even when the host has a separate public directory. */
final class RecruitmentAssetController extends AbstractController
{
    #[Route('/recrutement-assets/{file}', name: 'app_recruitment_asset', methods: ['GET', 'HEAD'])]
    public function asset(string $file, Request $request, #[Autowire('%kernel.project_dir%')] string $projectDir): BinaryFileResponse
    {
        $files = [
            'recruitment.css' => 'recruitment/recruitment.css',
            'recruitment.js' => 'recruitment/recruitment.js',
            'recruitment-form.js' => 'recruitment/recruitment-form.js',
            'tom-select.css' => 'recruitment/tom-select.css',
            'tom-select.min.js' => 'recruitment/tom-select.min.js',
            'leaflet.css' => 'vendors/leaflet/leaflet.css',
            'leaflet.js' => 'vendors/leaflet/leaflet.js',
        ];
        if (!isset($files[$file])) throw $this->createNotFoundException();
        $path = $projectDir.'/public/dist/'.$files[$file];
        if (!is_file($path)) throw $this->createNotFoundException();
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', str_ends_with($file, '.css') ? 'text/css; charset=UTF-8' : 'application/javascript; charset=UTF-8');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setPublic()->setMaxAge(0)->setAutoEtag()->setAutoLastModified();
        $response->headers->addCacheControlDirective('must-revalidate');
        $response->isNotModified($request);
        return $response;
    }
}
