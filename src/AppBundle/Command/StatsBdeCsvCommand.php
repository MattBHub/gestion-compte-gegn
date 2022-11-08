<?php

namespace AppBundle\Command;

use DateTime;
use Swift_Attachment;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class StatsBdeCsvCommand extends ContainerAwareCommand {

    private $fileName = "stats_bde.csv";
    private $filePath;

    protected function configure() {
        $this
            ->setName('app:user:stats_bde')
            ->setDescription('generate csv file')
            ->setHelp('No help');
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        echo "\n Start at " . date_format(new DateTime(), ' H:i:s');

        $this->filePath = $this->getContainer()->get('kernel')->getProjectDir() . DIRECTORY_SEPARATOR . $this->fileName;

        echo "\n Getting stats";
        $data = $this->getStatUsers();

        echo "\n Creating Csv file";
        $this->generateCsv($data);

        echo "\n Sending email";
        $this->sendEmail();

//        echo "\n Removing file";
//        unlink($this->filePath);

        echo "\n Done at " . date_format(new DateTime(), ' H:i:s');
    }

    function getStatUsers(): array {
        $connexion = $this->getContainer()->get('doctrine')->getManager()->getConnection();

        $query = "SELECT memberid, -- member_number, benefid,  
            CONCAT(UPPER(temp.lastname) , ' ', LOWER(temp.firstname)) AS nom,
            ROUND(compteur) AS compteur, 
            DATE_FORMAT(first_shift_date, '%d/%m/%Y') AS premier_ceneau,
            DATE_FORMAT(dernierceneau, '%d/%m/%Y') AS dernier_ceneau,
            DATE_FORMAT(datecreation, '%d/%m/%Y') AS date_creation,
            DATE_FORMAT(last_login, '%d/%m/%Y') AS derniere_connexion,
            frozen as gele 
            FROM 
                    (SELECT
                        (SELECT SUM(time) / 60 FROM time_log WHERE membership_id = m.id) AS compteur,
                  (SELECT MAX(date) FROM time_log tl WHERE tl.type = 1 AND tl.membership_id =  m.ID) AS dernierceneau,
                        m.id AS memberid,
                        m.withdrawn,
                        m.frozen,
                        m.member_number,
            -- 			m.frozen_change,
                        m.first_shift_date,
                        b.id AS benefid,
                        b.lastname ,
                        b.firstname,
                    r1.date as datecreation,
                        u.last_login
                        FROM membership m LEFT JOIN beneficiary b ON m.id = b.membership_id
                        LEFT JOIN registration r1 ON m.id = r1.membership_id LEFT JOIN fos_user u ON b.user_id = u.id 
                        LEFT JOIN time_log tl ON m.id = tl.membership_id
                        WHERE m.withdrawn = 0
            --				AND m.frozen = 1
            --				AND b.membership_id IN (SELECT membership_id AS sclr_39 FROM time_log GROUP BY membership_id HAVING SUM(time) < -6 * 60)
                    ) AS temp
            GROUP BY memberid;";
        $stmt = $connexion->prepare($query);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    function generateCsv($data) {
        $headers = ['Membre Id', 'Nom', 'Compteur', 'Premier_créneau', 'Dernier_créneau', 'Date_création', 'Dernière_connexion', 'Gelé'];

        // open the file for writing
        $file = fopen($this->filePath, 'w');

        // insert column headers
        fputcsv($file, $headers, ';');

        // for each user
        foreach ($data as $line) {
            fputcsv($file, array_values($line), ';');
        }

        // Close the file
        fclose($file);
    }

    function sendEmail() {

        $from = "no-reply@xxx.fr";
        $to = "BDE.xxx.fr";
        $toCC = "informatique.xxx.fr";
        $subject = "les stats du mois";
        $body = "Bonjour le BDE,<br><br>";
        $body .= "le relevé des compteurs des coopérateurs actualisé est en PJ. <br>";
        $body .= "En cas de problème, vous pouvez contacter le GDT informatique (en copie) si besoin. <br>";
        $body .= "Bonne journée";

        $mailer = $this->getContainer()->get('mailer');

        $message = (new \Swift_Message($subject))
            ->setFrom($from)
            ->addPart(
                $body,
                'text/html'
            );

        $message->setTo($to);
        $message->setCc($toCC);

        // Create the attachment
        $attachment = Swift_Attachment::fromPath($this->filePath)
            ->setFilename($this->fileName);

        // Attach it to the message
        $message->attach($attachment);


        $mailer->send($message);
    }
}