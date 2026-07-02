<?php

declare(strict_types=1);

namespace Tests\Services;

use App\Repositories\PortalRepository;
use App\Services\PortalService;
use PHPUnit\Framework\TestCase;

final class PortalServiceIdEmpleadoTest extends TestCase
{
    public function testDevuelveElIdEmpleadoDelRepositorio(): void
    {
        $repo = $this->createMock(PortalRepository::class);
        $repo->expects(self::once())
            ->method('idEmpleadoPorUsuario')
            ->with(7)
            ->willReturn(42);

        $service = new PortalService($repo);

        self::assertSame(42, $service->idEmpleado(7));
    }

    public function testDevuelveNullSiNoHayEmpleadoVinculado(): void
    {
        $repo = $this->createMock(PortalRepository::class);
        $repo->method('idEmpleadoPorUsuario')->willReturn(null);

        $service = new PortalService($repo);

        self::assertNull($service->idEmpleado(7));
    }
}
