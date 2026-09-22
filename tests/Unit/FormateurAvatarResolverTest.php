<?php

namespace App\Tests\Unit;

use App\Entity\{Formateur, Utilisateur};
use App\Service\Avatar\FormateurAvatarResolver;
use PHPUnit\Framework\TestCase;

final class FormateurAvatarResolverTest extends TestCase
{
    public function testUsesTheTrainerPhotoThenUserPhotoAndNeverAMissingFile(): void
    {
        $root = sys_get_temp_dir() . '/wikiformation-avatars-' . bin2hex(random_bytes(6));
        $trainerDirectory = $root . '/public/uploads/photos/formateur';
        $userDirectory = $root . '/public/uploads/photos/utilisateur';
        mkdir($trainerDirectory, 0700, true);
        mkdir($userDirectory, 0700, true);
        $image = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
        file_put_contents($trainerDirectory . '/trainer.gif', $image);
        file_put_contents($userDirectory . '/user.gif', $image);
        try {
            $trainer = (new Formateur())->setPhoto('trainer.gif')->setUtilisateur((new Utilisateur())->setPhoto('user.gif'));
            $resolver = new FormateurAvatarResolver($root);
            self::assertSame('uploads/photos/formateur/trainer.gif', $resolver->publicPath($trainer));
            $trainer->setPhoto('missing.gif');
            self::assertSame('uploads/photos/utilisateur/user.gif', $resolver->publicPath($trainer));
            $trainer->getUtilisateur()->setPhoto('missing.gif');
            self::assertNull($resolver->publicPath($trainer));
            $trainer->setPhoto('../utilisateur/user.gif');
            self::assertNull($resolver->publicPath($trainer));
        } finally {
            unlink($trainerDirectory . '/trainer.gif');
            unlink($userDirectory . '/user.gif');
            rmdir($trainerDirectory);
            rmdir($userDirectory);
            foreach (['/public/uploads/photos', '/public/uploads', '/public', ''] as $path) rmdir($root . $path);
        }
    }
}
