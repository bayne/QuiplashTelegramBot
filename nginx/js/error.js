import {htmlToElement} from "./util.js";

const ERRORS = {
    "ALREADY_ANSWERED": "You have answered all your prompts! Go back to the group chat and wait for the other players to answer."
}

export default class Error extends HTMLElement {
    constructor() {
        super();
        const shadow = this.attachShadow({mode: "open"});
        const message = this.getAttribute("message");
        shadow.append(htmlToElement(`
        <style>
            div {
                text-align: center;
                margin:auto;
                padding: 2em;
            }
        </style>`));
        shadow.append(htmlToElement(`<div>
            ${ERRORS[message]}
        </div>`));
    }
}