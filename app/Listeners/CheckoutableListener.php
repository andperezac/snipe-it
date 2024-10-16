<?php

namespace App\Listeners;

use App\Events\CheckoutableCheckedOut;
use App\Mail\CheckinAccessoryMail;
use App\Mail\CheckoutAccessoryMail;
use App\Mail\CheckoutAssetMail;
use App\Mail\CheckinAssetMail;
use App\Models\Accessory;
use App\Models\Asset;
use App\Models\CheckoutAcceptance;
use App\Models\Component;
use App\Models\Consumable;
use App\Models\LicenseSeat;
use App\Models\Recipients\AdminRecipient;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\CheckinAccessoryNotification;
use App\Notifications\CheckinLicenseSeatNotification;
use App\Notifications\CheckoutAccessoryNotification;
use App\Notifications\CheckoutAssetNotification;
use App\Notifications\CheckoutConsumableNotification;
use App\Notifications\CheckoutLicenseSeatNotification;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Exception;
use Illuminate\Support\Facades\Log;

class CheckoutableListener
{
    private array $skipNotificationsFor = [
        Component::class,
    ];

    /**
     * Notify the user and post to webhook about the checked out checkoutable
     * and add a record to the checkout_requests table.
     */
    public function onCheckedOut($event)
    {
        if ($this->shouldNotSendAnyNotifications($event->checkoutable)){
            return;
        }

        /**
         * Make a checkout acceptance and attach it in the notification
         */
        $acceptance = $this->getCheckoutAcceptance($event);
        $notifiable = $this->getNotifiable($event);
        $mailable = $this->getCheckoutMailType($event, $acceptance);
        // Send email notifications
        try {
                if  (!$event->checkedOutTo->locale){
                    $mailable->locale($event->checkedOutTo->locale);
                }
                Mail::to($notifiable)->send($mailable);
                Log::info('Sending email, Locale: ' .($event->checkedOutTo->locale ?? 'default'));
//                 Send Webhook notification
                if ($this->shouldSendWebhookNotification()) {
                    Notification::route(Setting::getSettings()->webhook_selected, Setting::getSettings()->webhook_endpoint)
                        ->notify($this->getCheckoutNotification($event, $acceptance));
                }
        } catch (ClientException $e) {
            Log::debug("Exception caught during checkout notification: " . $e->getMessage());
        } catch (Exception $e) {
            Log::debug("Exception caught during checkout notification: " . $e->getMessage());
        }
    }


    /**
     * Notify the user and post to webhook about the checked in checkoutable
     */    
    public function onCheckedIn($event)
    {
        Log::debug('onCheckedIn in the Checkoutable listener fired');

        if ($this->shouldNotSendAnyNotifications($event->checkoutable)) {
            return;
        }

        /**
         * Send the appropriate notification
         */
        if ($event->checkedOutTo && $event->checkoutable){
            $acceptances = CheckoutAcceptance::where('checkoutable_id', $event->checkoutable->id)
                                            ->where('assigned_to_id', $event->checkedOutTo->id)
                                            ->get();

            foreach($acceptances as $acceptance){
                if($acceptance->isPending()){
                    $acceptance->delete();
                }
            }
        }

        $notifiable = $this->getNotifiable($event);
        $mailable =  $this->getCheckinMailType($event);

        // Send email notifications
        try {
            if  (!$event->checkedOutTo->locale){
                $mailable->locale($event->checkedOutTo->locale);
            }
            Mail::to($notifiable)->send($mailable);
            \Log::info('Sending email, Locale: ' .$event->checkedOutTo->locale);
            // Send Webhook notification
            if ($this->shouldSendWebhookNotification()) {
                    Notification::route(Setting::getSettings()->webhook_selected, Setting::getSettings()->webhook_endpoint)
                        ->notify($this->getCheckinNotification($event));
                }
        } catch (ClientException $e) {
            Log::warning("Exception caught during checkout notification: " . $e->getMessage());
        } catch (Exception $e) {
            Log::warning("Exception caught during checkin notification: " . $e->getMessage());
        }
    }      

    /**
     * Generates a checkout acceptance
     * @param  Event $event
     * @return mixed
     */
    private function getCheckoutAcceptance($event)
    {
        $checkedOutToType = get_class($event->checkedOutTo);
        if ($checkedOutToType != "App\Models\User") {
            return null;
        }
        if (!$event->checkoutable->requireAcceptance()) {
            return null;
        }

        $acceptance = new CheckoutAcceptance;
        $acceptance->checkoutable()->associate($event->checkoutable);
        $acceptance->assignedTo()->associate($event->checkedOutTo);
        $acceptance->save();

        return $acceptance;      
    }

    /**
     * Gets the entities to be notified of the passed event
     * 
     * @param  Event $event
     * @return Collection
     */
    private function getNotifiable($event)
    {
        $notifiable = collect();

        /**
         * Notify who checked out the item as long as the model can route notifications
         */
        if (method_exists($event->checkedOutTo, 'routeNotificationFor')) {
            $notifiable->push($event->checkedOutTo);
        }

        /**
         * Notify Admin users if the settings is activated
         */
        if ((Setting::getSettings()) && (Setting::getSettings()->admin_cc_email != '')) {
            $adminRecipient= new AdminRecipient;
            $notifiable->push($adminRecipient->getEmail());
        }

        return new $notifiable;
    }

    /**
     * Get the appropriate notification for the event
     * 
     * @param  CheckoutableCheckedIn $event 
     * @return Notification
     */
    private function getCheckinNotification($event)
    {

        $notificationClass = null;

        switch (get_class($event->checkoutable)) {
            case Accessory::class:
                $notificationClass = CheckinAccessoryNotification::class;
                break;
            case Asset::class:
                $notificationClass = CheckinAssetNotification::class;
                break;    
            case LicenseSeat::class:
                $notificationClass = CheckinLicenseSeatNotification::class;
                break;
        }

        Log::debug('Notification class: '.$notificationClass);

        return new $notificationClass($event->checkoutable, $event->checkedOutTo, $event->checkedInBy, $event->note);  
    }

    /**
     * Get the appropriate notification for the event
     * 
     * @param  CheckoutableCheckedOut $event
     * @param  CheckoutAcceptance|null $acceptance
     * @return Notification
     */
    private function getCheckoutNotification($event, $acceptance = null)
    {
        $notificationClass = null;

        switch (true) {
            case $event->checkoutable instanceof Accessory:
                $notificationClass = CheckoutAccessoryNotification::class;
                break;
            case $event->checkoutable instanceof Asset:
                $notificationClass = CheckoutAssetNotification::class;
                break;
            case $event->checkoutable instanceof Consumable:
                $notificationClass = CheckoutConsumableNotification::class;
                break;
            case $event->checkoutable instanceof LicenseSeat:
                $notificationClass = CheckoutLicenseSeatNotification::class;
                break;
        }

        return new $notificationClass($event->checkoutable, $event->checkedOutTo, $event->checkedOutBy, $acceptance, $event->note);
    }
    private function getCheckoutMailType($event, $acceptance){
        $lookup = [
            Accessory::class => CheckoutAccessoryMail::class,
            Asset::class => CheckoutAssetMail::class,
//            Consumable::class =>
//            LicenseSeat::class =>
        ];
        $mailable= $lookup[get_class($event->checkoutable)];

        return new $mailable($event->checkoutable, $event->checkedOutTo, $event->checkedOutBy, $event->note, $acceptance);

    }
    private function getCheckinMailType($event){
        $lookup = [
            Accessory::class => CheckinAccessoryMail::class,
            Asset::class => CheckinAssetMail::class,
//            Consumable::class =>
//            LicenseSeat::class =>
        ];
        $mailable= $lookup[get_class($event->checkoutable)];

        return new $mailable($event->checkoutable, $event->checkedOutTo, $event->checkedInBy, $event->note);

    }

    /**
     * Register the listeners for the subscriber.
     *
     * @param  Illuminate\Events\Dispatcher  $events
     */
    public function subscribe($events)
    {
        $events->listen(
            \App\Events\CheckoutableCheckedIn::class,
            'App\Listeners\CheckoutableListener@onCheckedIn'
        ); 

        $events->listen(
            \App\Events\CheckoutableCheckedOut::class,
            'App\Listeners\CheckoutableListener@onCheckedOut'
        ); 
    }

    private function shouldNotSendAnyNotifications($checkoutable): bool
    {
        return in_array(get_class($checkoutable), $this->skipNotificationsFor);
    }

    private function shouldSendWebhookNotification(): bool
    {
        return Setting::getSettings() && Setting::getSettings()->webhook_endpoint;
    }
}
