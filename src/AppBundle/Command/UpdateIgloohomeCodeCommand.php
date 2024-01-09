<?php

namespace AppBundle\Command;

use AppBundle\Entity\Code;
use DateTime;
use Exception;
use Swift_Message;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class UpdateIgloohomeCodeCommand extends ContainerAwareCommand {

    protected function configure() {
        $this
            ->setName('app:code:update_igloohome')
            ->setDescription('Update igloohome code')
            ->setHelp('This command create an igloohome code using the API and set it the code table.
            More info here : https://igloocompany.stoplight.io/docs/igloohome-api/d9683e9f1bb3c-igloohome-api')
            ->addArgument('client_id', InputArgument::REQUIRED, 'Igloohome ClientId')
            ->addArgument('client_secret', InputArgument::REQUIRED, 'Igloohome ClientSecret')
            ->addArgument('device_id', InputArgument::REQUIRED, 'Igloohome DeviceId');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {

        $output->writeln('<fg=green;>Tentative de création d\'un nouveau code</>');

        date_default_timezone_set('Europe/Paris');

        $client_id = $input->getArgument('client_id');
        $client_secret = $input->getArgument('client_secret');
        $device_id = $input->getArgument('device_id');

        try {
            /* Get a token from API */
            $token = $this->getToken($output, $client_id, $client_secret);

            /* Get the list of all the Igloohome devices */
//        $this->getDevice($output, $token);

            /* Generate a new hourly code using the Api */
            $newCodeValue = $this->generateCode($output, $device_id, $token);

            /* Replacing code in database*/
            $this->insertCodeIntoDatabase($output, $newCodeValue);

            return 1;
        } catch (TransportExceptionInterface|ServerExceptionInterface|RedirectionExceptionInterface|ClientExceptionInterface|Exception $e){
            $this->sendEmail($e->getMessage());
            return 0;
        }

    }

    /**
     * @throws TransportExceptionInterface|Exception
     */
    protected function getToken(OutputInterface $output, string $clientId, string $clientSecret) {

        $credentials = base64_encode($clientId . ':' . $clientSecret);

        $client = HttpClient::create();
        $response = $client->request('POST', 'https://auth.igloohome.co/oauth2/token', [
            'headers' => [
                'Authorization' => 'Basic ' . $credentials,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'grant_type' => 'client_credentials',
                'scope' => 'igloohomeapi/algopin-hourly igloohomeapi/get-devices',
            ]
        ]);

        $responseJson = $response->getContent(false);
        $responseData = json_decode($responseJson);

        if (200 !== $response->getStatusCode()) {
            $msg = "Erreur à l'appel de l'API IglooHome - Code: " . $response->getStatusCode() . " - Message: " . $responseData->error;
            $output->writeln('<fg=red> '.$msg.' </>');
            throw new Exception($msg);
        }

        $output->writeln('<fg=green;> Token successfully generated </>');
//        var_dump($responseData->expires_in);
//        var_dump($responseData->token_type);
//        var_dump($responseData->access_token);

        return $responseData->access_token;
    }

    /**
     * @param OutputInterface $output
     * @param string $token
     * @return void
     * @throws TransportExceptionInterface|ServerExceptionInterface|RedirectionExceptionInterface|ClientExceptionInterface
     */
    protected function getDevice(OutputInterface $output, string $token) {
        $client = HttpClient::create();
        $response = $client->request('GET', 'https://api.igloodeveloper.co/igloohome/devices', [
            'headers' => [
                'Accept' => 'application/json, application/xml',
                'Authorization' => 'Bearer ' . $token,
            ],
        ]);

        $responseJson = $response->getContent(false);
        $responseData = json_decode($responseJson);

        if (200 !== $response->getStatusCode()) {
            $msg = "Erreur à la récupération des devices: " . $response->getStatusCode() . " - Message: " . $responseData->error;
            $output->writeln('<fg=red> '.$msg.' </>');
        }
        else {
            var_dump($responseData->payload);
        }
    }

    /**
     * @throws TransportExceptionInterface|ServerExceptionInterface|RedirectionExceptionInterface|ClientExceptionInterface|Exception
     */
    protected function generateCode(OutputInterface $output, string $lockId, string $token, string $start = null, string $end = null) {

        if (!$start) {
            $start = new DateTime();
            $start->setTime(07, 00);
            $start = date_format($start, DATE_W3C);
        }

        if (!$end) {
            $end = new DateTime();
            $end->setTime(22, 00);
            $end = date_format($end, DATE_W3C);
        }

        $client = HttpClient::create();
        $response = $client->request('POST', 'https://api.igloodeveloper.co/igloohome/devices/' . $lockId . '/algopin/hourly', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json, application/xml',
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'variance' => 1,
                'startDate' => $start,
                'endDate' => $end,
                'accessName' => date_format(new DateTime(), "d/m/Y") . " (auto)",
            ])
        ]);

        $responseJson = $response->getContent(false);
        $responseData = json_decode($responseJson);

        if (200 !== $response->getStatusCode()) {
            $msg = "Erreur à la création du code: " . $response->getStatusCode() . " - Message: " . $responseData->error;
            $output->writeln('<fg=red> '.$msg.' </>');
            throw new Exception($msg);
        }

        $output->writeln('<fg=green;> Code successfully generated </>');
//        var_dump($responseData->pin);
//        var_dump($responseData->pinId);

        return $responseData->pin;

    }

    protected function insertCodeIntoDatabase(OutputInterface $output, string $newCode) {

        /* Get the old open codes */
        $em = $this->getContainer()->get('doctrine')->getManager();
        $codeRepository = $em->getRepository('AppBundle:Code');
        $qb = $codeRepository->createQueryBuilder('c');
        $qb->where('c.closed = :closed')->setParameter('closed', 0);
        $open_codes = $qb->getQuery()->getResult();

        /* Get the admin user */
        $adminUsername = $this->getContainer()->getParameter('super_admin.username');
        $userRepository = $em->getRepository('AppBundle:User');
        $adminUSer = $userRepository->findOneByUsername($adminUsername);

        /* Insert the new code */
        $code = new Code();
        $code->setValue($newCode);
        $code->setClosed(false);
        $code->setCreatedAt(new DateTime());
        $code->setRegistrar($adminUSer);

        $em->persist($code);

        /* Close the old open codes */
        foreach ($open_codes as $open_code) {
            $open_code->setClosed(true);
            $em->persist($open_code);
        }

        $em->flush();

        $output->writeln('<fg=green;> Code inseré dans la base de donnée </>');
    }

    protected function sendEmail(string $msg) {
        $mailer = $this->getContainer()->get('mailer');
        $shiftEmail = $this->getContainer()->getParameter('emails.shift');
        $adminEmail = $this->getContainer()->getParameter('emails.admin');
        $mail = (new Swift_Message('[ESPACE MEMBRES] !! Echec de création du code du boitier'))
            ->setFrom($shiftEmail['address'], $shiftEmail['from_name'])
            ->setTo($adminEmail['address'])
            ->setBody($msg);
        $mailer->send($mail);
    }
}
