/**
 *  Ledger Presence
 *
 *  Copyright 2026 Chris Lacey
 *
 *  Licensed under the Apache License, Version 2.0 (the "License"); you may not use this file except
 *  in compliance with the License. You may obtain a copy of the License at:
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 *  Unless required by applicable law or agreed to in writing, software distributed under the License is distributed
 *  on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied. See the License
 *  for the specific language governing permissions and limitations under the License.
 *
 *  ---
 *
 *  Feeds the "Where you are" card on c.lacey.me from SmartThings presence.
 *
 *  A presence sensor knows arrival and departure but not coordinates, so this
 *  posts a place *name* and lets the ledger match it against its own `places`
 *  table. The name entered here has to match a row in that table exactly, which
 *  is why the setup screen says so rather than leaving it to be discovered.
 *
 *  The ledger answers with whatever is on the shopping list for that place, so
 *  arriving somewhere can raise a notification listing what to pick up.
 */

definition(
    name: "Ledger Presence",
    namespace: "christopherlacey",
    author: "Chris Lacey",
    description: "Tell Chris Lacey's Dashboard when you arrive at or leave a place, so it knows where you are.",
    category: "My Apps",
    iconUrl: "https://s3.amazonaws.com/smartapp-icons/Meta/life360.png",
    iconX2Url: "https://s3.amazonaws.com/smartapp-icons/Meta/life360@2x.png"
)

preferences {
    section("Which presence sensor is you") {
        input "presenceSensor", "capability.presenceSensor", title: "Presence sensor", required: true, multiple: false
    }

    section("Which place this sensor represents") {
        input "placeName", "text", title: "Place name",
              description: "Must match a row in the ledger's places table, exactly",
              required: true
    }

    section("Where to send it") {
        input "endpoint", "text", title: "Ledger endpoint",
              defaultValue: "https://c.lacey.me/api/location.php", required: true
        input "token", "password", title: "Location token (from the ledger's config.php)", required: true
    }

    section("Tell me what to pick up") {
        input "notifyOnArrive", "bool", title: "Notify when arriving somewhere with items on the list",
              defaultValue: true, required: false
    }
}

def installed() {
    log.debug "Ledger Presence installed: ${settings.placeName}"
    initialize()
}

def updated() {
    log.debug "Ledger Presence updated: ${settings.placeName}"
    unsubscribe()
    initialize()
}

def initialize() {
    subscribe(presenceSensor, "presence", presenceChanged)
}

def presenceChanged(evt) {
    // "present"/"not present" is the only thing a presence sensor reports, so it
    // maps straight onto the ledger's arrive/depart.
    def transition = evt.value == "present" ? "arrive" : "depart"

    log.debug "Ledger Presence: ${evt.displayName} ${evt.value} at ${settings.placeName}"

    postToLedger(transition)
}

private postToLedger(String transition) {
    def body = [
        label     : settings.placeName,
        transition: transition,
        source    : "smartthings"
    ]

    def params = [
        uri               : settings.endpoint,
        headers           : ["Authorization": "Bearer ${settings.token}"],
        requestContentType: "application/json",
        body              : body
    ]

    try {
        httpPost(params) { response ->
            if (response.status != 200) {
                log.warn "Ledger Presence: endpoint returned ${response.status}"
                return
            }

            def waiting = response.data?.waiting

            // Only worth a notification on the way in, and only when there is
            // actually something to pick up here.
            if (transition == "arrive" && notifyOnArrive && waiting) {
                def place = response.data?.place ?: settings.placeName
                sendPush("At ${place}: ${waiting.join(', ')}")
            }
        }
    } catch (e) {
        // A dashboard update is never worth throwing an error into the hub's
        // event loop over — log it and let the next transition try again.
        log.warn "Ledger Presence: could not reach the ledger (${e.message})"
    }
}
