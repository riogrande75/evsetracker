Tracker for my EV charger on pv electricity surplus
This script reads actual power reading from my house from a shared memory object (shmop 0x6301, filled from my script sdm630poller) and based on some additional static values regarding my solar system, it calculates correct current/charge power that gets set on my evse-wifi.
Additionally capacity of my house' battery should be taken into account (tbd).
Adaptive charging can be controlled via contents of file $adaptfile (EIN / AUS) that can be set via smart home application.
