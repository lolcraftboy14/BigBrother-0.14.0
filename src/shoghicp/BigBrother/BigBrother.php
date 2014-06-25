<?php

/*
 * BigBrother plugin for PocketMine-MP
 * Copyright (C) 2014 shoghicp <https://github.com/shoghicp/BigBrother>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
*/

namespace shoghicp\BigBrother;

use phpseclib\Crypt\RSA;
use shoghicp\BigBrother\network\translation\Translator;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\network\protocol\Info;
use pocketmine\plugin\PluginBase;
use shoghicp\BigBrother\network\Info as MCInfo;
use shoghicp\BigBrother\network\ProtocolInterface;
use shoghicp\BigBrother\network\ServerThread;
use shoghicp\BigBrother\network\translation\Translator_16;
use shoghicp\BigBrother\tasks\GeneratePrivateKey;

class BigBrother extends PluginBase implements Listener{

	/** @var ServerThread */
	private $thread;

	/** @var ProtocolInterface */
	private $interface;

	/** @var RSA */
	protected $rsa;

	protected $privateKey;

	protected $publicKey;

	protected $onlineMode;

	/** @var Translator */
	protected $translator;

	public function onEnable(){
		$this->getServer()->getLoader()->add("phpseclib", [
			$this->getFile() . "src"
		]);

		class_exists("phpseclib\\Math\\BigInteger", true);
		class_exists("phpseclib\\Crypt\\Random", true);
		class_exists("phpseclib\\Crypt\\Base", true);
		class_exists("phpseclib\\Crypt\\Rijndael", true);
		class_exists("phpseclib\\Crypt\\AES", true);

		$this->saveDefaultConfig();
		$this->reloadConfig();

		//TODO: work on online mode
		$this->onlineMode = (bool) $this->getConfig()->get("online-mode");

		if(Info::CURRENT_PROTOCOL === 16){
			$this->translator = new Translator_16();
		}else{
			$this->getLogger()->critical("Couldn't find a protocol translator for #".Info::CURRENT_PROTOCOL .", disabling plugin");
			$this->getPluginLoader()->disablePlugin($this);
			return;
		}

		$this->rsa = new RSA();



		$this->getServer()->getPluginManager()->registerEvents($this, $this);

		if($this->onlineMode){
			$task = new GeneratePrivateKey($this->getServer()->getLogger(), $this->getServer()->getLoader());
			$this->getServer()->getScheduler()->scheduleAsyncTask($task);
		}else{
			$this->enableServer();
		}
	}

	public function receiveCryptoKeys($privateKey, $publicKey){
		$this->privateKey = $privateKey;
		$this->publicKey = $publicKey;
		$this->rsa->setEncryptionMode(CRYPT_RSA_ENCRYPTION_PKCS1);
		$this->rsa->loadKey($this->privateKey);
		$this->enableServer();
	}

	protected function enableServer(){
		$port = (int) $this->getConfig()->get("port");
		$interface = $this->getConfig()->get("interface");
		$this->getLogger()->info("Starting Minecraft: PC server on ".($interface === "0.0.0.0" ? "*" : $interface).":$port version ".MCInfo::VERSION);
		$this->thread = new ServerThread($this->getServer()->getLogger(), $this->getServer()->getLoader(), $port, $interface);

		$this->interface = new ProtocolInterface($this, $this->thread, $this->translator);
		$this->getServer()->addInterface($this->interface);
	}

	/**
	 * @return bool
	 */
	public function isOnlineMode(){
		return $this->onlineMode;
	}

	public function getASN1PublicKey(){
		$key = explode("\n", $this->publicKey);
		array_pop($key);
		array_shift($key);
		return base64_decode(implode(array_map("trim", $key)));
	}

	public function decryptBinary($secret){
		return $this->rsa->decrypt($secret);
	}

	public function onDisable(){
		//TODO: make it fully /reload compatible (remove from server)
		$this->interface->shutdown();
		$this->thread->join();
	}

	/**
	 * @param PlayerLoginEvent $event
	 *
	 * @priority MONITOR
	 */
	public function onLogin(PlayerLoginEvent $event){
		/*$player = $event->getPlayer();
		if($player instanceof DesktopPlayer){
			if(!$event->isCancelled()){
				$player->bigBrother_authenticate();
			}
		}*/
	}

}