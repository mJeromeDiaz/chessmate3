<?php

declare(strict_types=1);

namespace App\Mailer;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * `MAILER_DSN=mailfunction://default`: PHP's mail() ({@see MailFunctionTransport}).
 */
#[AutoconfigureTag('mailer.transport_factory')]
final class MailFunctionTransportFactory extends AbstractTransportFactory
{
    public function create(Dsn $dsn): TransportInterface
    {
        if ('mailfunction' !== $dsn->getScheme()) {
            throw new UnsupportedSchemeException($dsn, 'mailfunction', $this->getSupportedSchemes());
        }

        return new MailFunctionTransport(null, $this->dispatcher, $this->logger);
    }

    /**
     * @return list<string>
     */
    protected function getSupportedSchemes(): array
    {
        return ['mailfunction'];
    }
}
